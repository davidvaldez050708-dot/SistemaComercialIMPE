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

## Entregas posteriores, expresamente no incluidas
1B: reparto de 80 registros Meta por asesor/día, con pendientes y comprobación de duplicados.
1C: asignación inmediata de Aliados según programa y preferencia de horario, sin límite; atender jornadas y ausencias/excepciones calendarias.
1D: escenarios de regresión y publicación controlada.
Los dos embudos de Recuperación, la llamada para remarketing, resultados vinculados a la ficha y conversión manual a Inscrito pertenecen a fases posteriores; no se anticipan con estados o métricas ficticios.
