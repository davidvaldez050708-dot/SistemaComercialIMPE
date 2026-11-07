# Prospectos · Motores 1B (Meta) y 1C (Aliados)

## Flujo confirmado
**Tania descarga desde Meta (formularios de clientes potenciales) uno o más archivos** para distintos formularios, por ejemplo Titulación por experiencia y Bachillerato. Puede subirlos por separado en la misma noche. La importación valida, clasifica por **form_id** y programa confirmado, y guarda los prospectos en el almacén central. Al terminar **todos** los lotes de un archivo, registra su `lote_ref` como confirmado. Otro archivo genera otro lote independiente. No se deduce programa por nombres de campañas.

El motor **Meta nocturno** procesa todos los leads nuevos de lotes confirmados y todavía sin asignación. Distribuye para la siguiente jornada configurada, hasta **80 nuevos por asesor por fecha**, siempre que el asesor esté activo, trabaje ese día y tenga el código exacto del programa en `prospecto_asesores_programas`. Los sobrantes y programas sin asesor quedan pendientes. La cuota histórica se calcula en la nueva tabla auditada y **no** disminuye si una asignación deja de estar vigente.

El motor **Aliados inmediato** se ejecuta únicamente **después del commit** de la solicitud y su incorporación al almacén central; no modifica `formulario_registros`. Solo asigna registros con procedencia raíz `ALIADO`, un programa inequívoco, asesor activo y franja compatible. Si la persona indica 17–19 y el asesor trabaja 16–21, el motor encuentra la primera franja común, confirma fecha y registra la próxima atención en UTC. **Sin cuota diaria de 80**. Registros sin zona horaria demostrable, sin programa claro o sin asesor compatible quedan pendientes. Preferencias sin horario específico pueden usar el próximo turno del asesor.

## Seguridad e invariantes
- **Ambos motores inician desactivados** en `prospecto_distribucion_control`. Ejecutar la migración o publicar archivos no inicia reparto. Activación separada y autorizada tras pruebas reales.
- No se reescriben asignaciones históricas, no se importan leads antiguos al motor automáticamente. El importador nuevo solo declara lotes **completos** desde su instalación; cualquier carga a medias queda fuera.
- `prospecto_distribucion_registros` incluye un UNIQUE por prospecto, un UNIQUE de asignación y conteo Meta por fecha/asesor. `prospecto_asignaciones` mantiene su unicidad por prospecto vigente. Bloqueo global MySQL `GET_LOCK` + transacciones + `FOR UPDATE` evitan dobles repartos.
- No se fusionan personas por teléfono, ni se crea una entrada en la agenda provisional. Mi cartera lee las asignaciones vigentes. El embudo comercial será donde asesores llamen, gestionen estados y registren resultados; **este PR no cambia telefonía, Zadarma, grabaciones ni embudo**.
- La zona de contacto explícita o derivada se acepta solo cuando puede confirmar `America/Mexico_City` (México, estados seguros). Estados fronterizos, EEUU y zonas ambiguas: `ZONA_HORARIA_PENDIENTE` y revisión posterior, sin adivinar ni asignar a otro horario.
- El script CLI solamente se ejecuta en PHP CLI. La operación real requiere `--apply --confirmar-produccion`. Con interruptores apagados no cambia datos. La versión `--preview` no escribe a la BD y simula virtualmente la capacidad.
- Si Aliados llega cuando el motor está apagado/ocupado, queda registrado como pendiente cuando la estructura está instalada. Se puede reintentar más tarde sin duplicar.

## Archivo real de Titulación recibido
Tabulado UTF-16LE, sin extensión, 1,271 filas de 16 columnas, 1 formulario `Titulacion Exp`, ID `f:1132366336136623`, 9 campañas, 1,271 IDs únicos. Dos filas de una campaña que incluye la palabra «BACHILLERATO» **siguen perteneciendo al formulario de Titulación** hasta que Tania establezca una excepción. No almacenar los datos personales ni el archivo original en repositorio, reportes públicos o CI. El archivo Bachillerato aún no se recibió y sus encabezados deben validarse al subirlo.

`ProspectoMetaArchivo` soporta CSV/TSV UTF-8, UTF-16LE/BE, Windows-1252 y XLSX primera hoja. Límites: 8MB, 5,000 filas, 80 columnas, 32MB de XML descomprimido. Obligatorias `id`, `created_time`, `full_name`, `phone_number`. Opcionales `ad_id`, `ad_name`, `adset_id`, `adset_name`, `campaign_id`, `campaign_name`, `form_id`, `form_name`, `is_organic`, `platform`, `estado`, `retailer_item_id`. Se aceptan columnas adicionales pero no se usan para asignación.

## Estructura y despliegue protegido
Migración: `database/migrations/2026_11_06_prospectos_motores_distribucion.sql`.
Tablas: `prospecto_distribucion_control`, `prospecto_meta_lotes_confirmados`, `prospecto_distribucion_registros`, `prospecto_distribucion_pendientes`, `prospecto_distribucion_corridas`.

**Orden obligatorio tras respaldar la base**:
1. Comprobar esquema y SHA de los archivos vivos de Hostinger contra `tools/prospectos_motores_distribucion_release.json`; ejecutar solo `python3 tools/publicar_prospectos_motores.py --site RUTA --check`. Resolver conflictos personalizados, nunca sobrescribir archivos desconocidos.
2. Con SQL revisado, ejecutar la migración en la base autorizada; debe crear las cinco tablas y dejar ambos interruptores en 0. No modificar prospectos ni cupos existentes.
3. Aplicar solo cinco PHP: `ProspectoMetaImportador.php`, `ProspectoCentralIncorporacionService.php`, `ProspectoDistribucionReglas.php`, `ProspectoDistribucionMotor.php`, `ProspectoDistribucionCli.php`. Respaldo/restore privado. No se publica el directorio `tools` al CRM.
4. Probar lectura y `--preview` en entorno de pruebas, importar archivos ficticios (Titulación + Bachillerato), registrar Aliados controlados. Comprobar `Mi cartera` y cuotas.
5. Activar Aliados explícitamente en un cambio independiente autorizado; Meta se activa después de acordar y probar la hora nocturna con Tania. **La instalación no configura ni activa un cron**.
6. Comando de simulación Meta: `php app/services/ProspectoDistribucionCli.php --meta --preview`; reparto autorizado: `php app/services/ProspectoDistribucionCli.php --meta --apply --confirmar-produccion`. Por defecto, fecha de mañana en Ciudad de México y hasta 2,000 solicitudes inspeccionadas por ejecución. También `--reintentar-aliados --preview` y `--reintentar-aliados --apply --confirmar-produccion`. La programación real de cron se hará en Hostinger tras autorización.

**Limitación pendiente de revisión de producción:** el lote nocturno analiza hasta 2,000 solicitudes por ejecución; si hay más, se requiere procesamiento paginado sin inanición para garantizar que ningún formulario quede al final de la cola. No habilitar cron para altos volúmenes sin resolver esto.

## Pruebas
`tests/prospectos_motores_reglas_unit.php` y `tests/prospectos_motores_db.php` sobre MySQL desechable: dos archivos, 82 Titulación + 8 Bachillerato, 80 por asesor, dos excedentes pendientes, reintentos idempotentes, asignación inmediata compatible, horario diferido, zona pendiente, asesor inactivo, simulación y reintentos de pendientes. `tests/prospectos_motores_release_test.py` revisa paquete incremental de cinco archivos, restauración y conflictos. CI `.github/workflows/prospectos-motores-distribucion.yml`.
