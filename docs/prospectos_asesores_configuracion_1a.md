# Proceso Comercial IMPE · Entrega 1A: configuración de asesores

## Alcance confirmado (06/11/2026)
- Cada usuario existente con rol **Asesor de Ventas** puede tener exactamente **un programa educativo activo**, validado contra `formulario_programas`.
- Jornada: `MEDIO_TIEMPO` o `TIEMPO_COMPLETO`, días y horas de entrada/salida (horario local de México, sin turnos que crucen medianoche en esta entrega).
- Campañas Meta: **80 prospectos nuevos por asesor y día**; cuota fija, no editable en este módulo.
- Aliados: **sin límite diario**. El horario del prospecto deberá respetarse en la fase de distribución.
- Configurar no asigna, reasigna ni cambia etapas comerciales. `prospecto_asignaciones`, `prospecto_intereses`, teléfonos, PBX y grabaciones permanecen intactos.

## Dónde aparece
`Prospectos → Asignaciones`: subtabs `Configuración de asesores`, `Distribución diaria`, `Historial`.
La primera permite a personas autorizadas editar cada asesor activo, con un formulario de jornada y siete días. Distribución diaria informa que la fase 1B no está disponible; Historial mantiene la consulta anterior.
Si faltan las tablas de configuración, la vista indica que no está habilitada y no muestra formularios de guardado. No se ejecuta DDL desde solicitudes web.

## Seguridad
- Nuevo permiso `prospectos.configurar_asesores`, adicional a `prospectos.ver_todos`; inicial únicamente Administrador. Coordinador puede obtenerlo desde Roles y permisos mediante concesión explícita.
- POST autenticado, verificación CSRF, datos validados también en servidor.
- Se comprueba contra `usuarios` y el rol real `Asesor de Ventas`, con usuario activo.
- Programa debe existir y estar activo en `formulario_programas`.
- Una configuración tiene versión. Cambios con versión obsoleta se rechazan.
- Transacción de guardado con bloqueo de usuario `FOR UPDATE` y auditoría de configuración y horarios.
- Contraseñas, tokens, números y datos de prospectos no se almacenan en la auditoría nueva.

## Estructura
`database/migrations/2026_11_06_prospectos_asesores_config.sql` crea únicamente tres tablas nuevas y el permiso independiente.
No altera registros existentes, no modifica `RolModel.php` ni ejecuta automáticamente SQL.
El esquema exige previamente `usuarios`, `roles`, `permisos`, `rol_permisos` y `formulario_programas`.

**No aplicar la migración en Hostinger todavía.** Primero: comparar sus SHA/estructura y revisar que los publicadores anteriores de Prospectos están efectivamente instalados. Preparar un publicador selectivo con `--check`, respaldo, `--apply` explícito y `--restore`; verificar permisos y sesión autorizada. Las personalizaciones instaladas de Hostinger son la fuente de verdad y cualquier conflicto debe detener el proceso antes de escribir.

## Pruebas
```sh
php -l app/controllers/ProspectoController.php
php -l app/services/ProspectoAsesorConfiguracionService.php
php -l app/views/prospectos/central.php
php -l app/views/prospectos/partials/configuracion_asesores.php
php tests/prospectos_asesores_config_unit.php
```
La integración `tests/prospectos_asesores_config_db.php` requiere MySQL **desechable** y `IMPE_ASESORES_TEST_DB=YES`, para crear y borrar una base aislada con nombre aleatorio. No usar credenciales ni conexión a la base real.
El workflow `.github/workflows/prospectos-asesores-config.yml` ejecuta sintaxis, pruebas unitarias y transacciones en MySQL 8.

## Conciliación con Hostinger: SHA proporcionados el 06/11/2026

| Archivo existente | SHA-256 confirmado | Acción |
|---|---|---|
| `app/controllers/ProspectoController.php` | `d5c1bc9d555773de82f34388667826dceffc28265951afdc8126ba6260aff234` | Actualizar tras `--check` |
| `app/views/prospectos/central.php` | `e083eaf8530917de928ce84918b84c7ea09e885b965ec85062d3bd1cdd42efba` | Actualizar tras `--check` |
| `public/css/prospectos_central.css` | `93600e7097cee8c6aebb6396a163faf033cc6ef3360b2fa93206959c6bc0993a` | Dependencia en sólo lectura |
| `app/models/RolModel.php` | `51678bbbaa437d278859dc33d25319b808dcd3a75cb50b4d6992` | Versión personalizada: no reemplazar |

Las tres primeras huellas corresponden exactamente a las del objetivo del paquete `prospectos_campanias_release.json`, corroboradas con SHA-256 sobre el código base real del PR #191. La cuarta huella es personalizada y no coincide con las variantes anteriores; se conserva tal cual.

## Publicador sellado, preparado, todavía NO instalado

Archivos de la entrega:
- `tools/publicar_prospectos_asesores_1a.py`: publicador `--check`, `--apply`, `--restore` Python 3.6+, sin SQL.
- `tools/prospectos_asesores_1a_release.json`: manifiesto con los dos SHA-256 originales validados, contenido completo y huellas objetivo de cinco archivos.
- `tests/prospectos_asesores_publicacion_test.py`: ejercita estos modos sobre una copia de pruebas, incluida la conservación de `RolModel.php`.

**Archivos modificables:** controlador y vista `central.php` (sobre los hashes anteriores) más tres archivos nuevos: servicio, vista parcial y CSS. **No cambiar:** `RolModel.php`, `prospectos_central.css`, telefonía, datos de prospectos, formularios, asignaciones o tablas existentes.

Comprobación SSH autorizada **solo lectura** (ajustar `REV` con el SHA exacto del commit validado al descargar):
```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
REV="<SHA_CONFIRMADO_DEL_PR_235>"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV/tools"
mkdir -p "$HOME/impe-asesores-1a-check"
cd "$HOME/impe-asesores-1a-check"
curl -fLsS "$BASE/publicar_prospectos_asesores_1a.py" -o publicar_prospectos_asesores_1a.py
curl -fLsS "$BASE/prospectos_asesores_1a_release.json" -o prospectos_asesores_1a_release.json
python3 publicar_prospectos_asesores_1a.py --site "$SITIO" --check
```
No usar `--apply` ni la migración hasta: CI específico aprobado, preflight compatible, confirmación del estado real de permisos/estructura en Hostinger y autorización del responsable. Un cambio de SHA provoca `CONFLICTO` sin escrituras.

`--apply` (únicamente en instalación posterior autorizada) exige `--backup-root "$HOME/respaldos-impe-asesores"`, guarda originales fuera de `public_html`, y `--restore RUTA_RESPALDO` valida integridad antes de restituir. El publicador no ejecuta DDL ni DML: migración de las tres tablas y permiso es una acción independiente que requiere respaldo habitual de base y revisión de SQL.

## Entregas posteriores, expresamente no incluidas
1B: reparto de 80 registros Meta por asesor/día, con pendientes y comprobación de duplicados.
1C: asignación inmediata de Aliados según programa y preferencia de horario, sin límite; atender jornadas y ausencias/excepciones calendarias.
1D: escenarios de regresión y publicación controlada.
Los dos embudos de Recuperación, la llamada para remarketing, resultados vinculados a la ficha y conversión manual a Inscrito pertenecen a fases posteriores; no se anticipan con estados o métricas ficticios.
