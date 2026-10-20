# Activar Handly en Aliados internacionales (PR #112)

Esta entrega conserva íntegros el token, la configuración y los registros del PR #111. Solo añade consulta administrativa y presentación. No cambia el catálogo de formularios generales, no crea una institución territorial mexicana, no ejecuta SQL y no añade QR ni otras funciones.

Handly Lenguaje Center aparece con contacto **Adalid Kuribeña**, teléfono **7775635090** y enlaces exclusivos de **Registro** e **Inscripción**. Sus programas autorizados siguen siendo exactamente **Bachillerato General** y **Titulación por experiencia laboral**, validados al guardar por PR #111. La consulta muestra solo programas activos; las respuestas históricas permanecen aunque se revoquen aliado o programa.

## Requisitos

1. Tener PR #111 instalado y su preparación de BD validada. Integrar en GitHub no acredita su instalación en Hostinger. Si falta, seguir primero [la guía de PR #111](handly_programas_hostinger.md), con copia recuperable de BD previa. No regenerar el token ni borrar registros existentes.
2. Acceso SSH al alojamiento real y PHP 8, Git y utilidades de shell. No necesita Node ni Python en Hostinger.
3. La vista exige sesión, rol Administración (`rol_id=1`) y permiso `aliados.ver`. Otros roles reciben 403; sin sesión, 401. No ampliar permisos para eludir esta restricción. Respuestas con datos personales y enlaces exclusivos usan `Cache-Control: no-store, private`.

## Publicación de los cuatro archivos

Ejecutar desde SSH, usando la cuenta propietaria del sitio. El directorio predeterminado es `$HOME/domains/fundacionrededumex.org/public_html`; si el sitio usa otro, exportar `IMPE_INTERNACIONALES_PUBLIC` con su ruta real antes de comprobar o aplicar.

Descargar fuera de `public_html` el publicador fijado a la entrega revisada:

```bash
curl --fail --location --proto '=https' --tlsv1.2 \
  'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/33fa8f569d60d7f6a9dec8bd472f36530bffd8b5/tools/publicar_aliados_internacionales_hostinger.sh' \
  -o "$HOME/publicar-aliados-internacionales.sh"
bash "$HOME/publicar-aliados-internacionales.sh" --check
```

`--check` prepara y valida parches sin cambiar archivos del sitio ni consultar o modificar BD. Clona las fuentes en una carpeta privada. Revisa únicamente:

- `app/controllers/AliadoController.php`
- `app/models/AliadoInternacionalModel.php`
- `app/views/aliados/index.php`
- `app/views/aliados/internacionales.php`

Admite la base con PR #111, personalizaciones PHP fuera de los bloques tocados y CRLF. Reconoce una entrega ya instalada. Ante conflictos, archivos nuevos ajenos, enlaces simbólicos o modificaciones posteriores se detiene; no forzar reemplazos ni usar `git pull` en `public_html`.

Cuando `--check` termine compatible, aplicar:

```bash
bash "$HOME/publicar-aliados-internacionales.sh" --apply
```

`--apply` crea respaldo privado en `$HOME/respaldos-crm/aliados-internacionales-FECHA-PID`, conserva permisos de archivos existentes y escribe cada archivo atómicamente. Comprueba que no cambiaron desde la revisión y restaura los alcanzados si falla. No despliega configuración, credenciales, migraciones ni archivos de formularios de PR #111.

## Comprobación de activación

1. Entrar al CRM como Administración con `aliados.ver`: **Aliados → Ver aliados internacionales** (ruta `index.php?controller=aliado&action=internacionales`).
2. Confirmar Handly, contacto y teléfono exactos; debe aparecer activo y con los dos programas autorizados. Si falta la configuración o falla una consulta, la página responde 503 y no ofrece información parcial: revisar instalación de PR #111 y registro privado del servidor.
3. Abrir ambos enlaces desde la tarjeta. Confirmar que contienen el token ya existente (no divulgarlo en tickets ni capturas), que los formularios muestran solo los dos programas y que el formulario general conserva su catálogo.
4. Ejecutar las verificaciones controladas de PR #111: envío por ambos tipos, reenvío sin duplicación, rechazo 422 de Inglés sin inserción y consulta de procedencia en esta vista. Los datos de prueba enviados al sitio real son registros reales: usar datos autorizados y conservar evidencia privada de la comprobación.
5. Confirmar rechazo 403 para otro rol y que Registro e Inscripción no mezclan personas aunque coincidan sus IDs. La tabla muestra las últimas 150 respuestas de aliados externos; no incluye formularios generales ni aliados territoriales.

Solo después de estas comprobaciones puede afirmarse que la función está activa en producción. Las pruebas locales y GitHub Actions no son evidencia de despliegue.

## Restauración

Usar la ruta exacta impresa al aplicar:

```bash
bash "$HOME/publicar-aliados-internacionales.sh" --restore \
  "$HOME/respaldos-crm/aliados-internacionales-FECHA-PID"
```

La restauración verifica huellas, rechaza archivos editados después de publicar y puede repetirse. Revierte solo estos archivos. No borra ni restaura BD, tokens, programas, respuestas o procedencias de PR #111. Conservar esos datos.

## Evidencia automatizada

- Pruebas de BD con `ONLY_FULL_GROUP_BY`: consultas, programas activos, preservación íntegra de cinco tablas, procedencia por tipo, capturas generales excluidas e historial de aliados inactivos.
- Controlador real: 401/403/200 y fallo de estructura 503 sin enlaces parciales; escape de datos y contacto en la vista real.
- Navegador y HTTP reales: vista internacional, enlaces, permisos y tamaños 1440/390; regresión completa de formularios de PR #111.
- Publicador real en cinco réplicas: base, personalización PHP, CRLF, conflicto y archivo nuevo existente. Comprobación sin escrituras, instalación, idempotencia, restauración exacta y rechazo de restauración sobre edición posterior.
