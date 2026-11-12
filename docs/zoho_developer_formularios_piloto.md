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

## Validación automática sin crear otra base en Hostinger

El workflow `.github/workflows/zoho-developer-piloto.yml` inicia un contenedor
**MySQL 8 temporal**, crea las tablas sintéticas de formularios, y recorre:
guardado local real -> cola -> worker -> OAuth simulado -> organización
simulada -> Upsert simulado -> estado e ID de sincronización.

Prueba: fallo HTTP 503 seguido de reintento, no duplicación en Zoho simulado,
bloqueo ante organización incorrecta, exclusión de inscripciones y
correos reales, y persistencia de los registros locales.

**No se usan tokens, credenciales ni peticiones a Zoho reales** dentro del
workflow. La base MySQL temporal desaparece al concluir GitHub Actions;
no es necesario crear ninguna base de pruebas en Hostinger.

Esta prueba de integración simulada no sustituye una prueba final de API
Developer real ni las verificaciones de compatibilidad/privacidad del
sitio operativo.

## Conciliación aditiva con Hostinger (solo lectura por defecto)

El modelo operativo de Hostinger **no coincide** con el modelo antiguo de esta
rama. Conserva funcionalidades que no pueden reemplazarse: campos de
`horario_contacto`, ubicación internacional, convocatorias de aliados,
`guardarOriginal()`, `insertar()` e incorporación comercial central.

**NO copiar `app/models/FormularioRegistroModel.php` del PR al sitio real.**
La entrega segura consiste en los nuevos servicios Zoho y un parche de
**un solo bloque** dentro del `guardar()` existente. El bloque se ejecuta
después de `ProspectoCentralIncorporacionService::despuesDeGuardar()`;
solo encola registros autorizados, no inscripciones, no lanza HTTP y
captura cualquier excepción sin alterar el guardado ya realizado.

El script `tools/zoho_conciliar_formulario_hostinger.py` realiza lo siguiente:

1. Comprueba que la ruta sea el sitio productivo conocido y que el SHA256
   de `FormularioRegistroModel.php` comience por
   `5089e339748fa7cd` (diagnóstico de Hostinger 2026-11-12).
2. Requiere que permanezcan intactas todas las anclas del flujo actual,
   incluido `horario_contacto`, y rechaza la instalación si cambiaron.
3. Solo agrega el bloque dentro de `guardar()`; confirma que no elimina
   ni altera una sola línea del archivo original.
4. En modo `--check` no escribe. En modo `--prepare` produce una
   copia privada **fuera de `public_html`**, que no se despliega sola.
   Nunca escribe directamente dentro del sitio, ni hace SQL.

Revisión de solo lectura:

~~~bash
python3 "$HOME/crm-zoho-developer-piloto/tools/zoho_conciliar_formulario_hostinger.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" --check
~~~

**No ejecutar el modo de preparación ni publicar archivos** sin haber
corroborado este chequeo y la revisión independiente de migración, BD,
configuración y comportamiento del proceso comercial.

La prueba automatizada `tests/zoho_hostinger_parche_smoke.py`
comprueba el único bloque añadido, conserva los componentes de producción,
valida PHP y verifica que el parche falle de forma segura si el archivo
cambió o ya estaba adaptado.

## Preparación de una única tabla auxiliar en la BD existente

La inspección de Hostinger de 2026-11-12 confirmó que
`formulario_registros` y `formulario_inscripciones` son InnoDB y que
`formulario_registros.id` es `INT UNSIGNED`. La tabla adicional
`zoho_dev_registros_pendientes` todavía no existe. La definición
`database/zoho_dev_registros_pendientes.sql` utiliza `BIGINT UNSIGNED`
en `registro_id` (compatible para almacenar esos IDs) y NO agrega
claves foráneas, columnas a formularios, ni cambia datos existentes.

Un instalador estricto en `tools/zoho_dev_cola_instalar.php` funciona
desde CLI con la configuración de MySQL del sitio. Modos:

- `--check`: consulta exclusivamente INFORMATION_SCHEMA, informa
  compatibilidad y existencia de la cola; NO realiza escrituras.
- `--install --confirm-new-table`: requiere confirmación explícita,
  comprueba las tablas existentes y ejecuta un único
  `CREATE TABLE IF NOT EXISTS zoho_dev_registros_pendientes` si falta;
  después comprueba estructura e índice único. No sincroniza ni activa
  cron y no sustituye el modelo. Requiere respaldo vigente y autorización
  separada para cualquier DDL en la BD operativa.
- Si la cola ya existe, la valida y NO realiza DDL adicional; si el
  esquema es incompatible, aborta en lugar de alterarlo.

Para comprobar sin escribir desde la copia privada del piloto:

~~~bash
php "$HOME/crm-zoho-developer-piloto/tools/zoho_dev_cola_instalar.php" --check
~~~

La prueba en GitHub Actions ejecuta tanto la instalación como la
reinstalación simulada contra una BD MySQL efímera. **No ejecutar la
instalación en el servidor hasta que esa prueba termine correctamente y
el respaldo de producción haya sido verificado.**

Nótese que el piloto solo exporta correos de prueba `@example.com`
autorizados explícitamente; no se debe registrar un prospecto ficticio
mediante el formulario productivo, porque allí se ejecuta primero la
incorporación al embudo comercial. La prueba funcional de envío de
formulario debe realizarse en un entorno de prueba separado.

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
