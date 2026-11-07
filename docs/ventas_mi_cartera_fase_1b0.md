# Fase 1B-0 · Mi cartera privada para Asesores de Ventas

**Precede** al motor de distribución automática 1B. Esta entrega es exclusivamente de lectura sobre asignaciones reales.

## Navegación y usuarios
- Asesor de Ventas (rol existente con `rol_id=3`): en menú escritorio y móvil `COMERCIAL → Mi cartera`.
- `Prospectos` es el almacén administrativo. El rol Asesor de Ventas nunca puede entrar allí, incluso si accidentalmente conserva `prospectos.ver_todos`.
- Los demás roles no reciben la vista Mi cartera aunque se les asigne el permiso accidentalmente; `MiCarteraController` requiere simultáneamente rol 3 y permiso activo.
- Permiso granular `prospectos.mi_cartera` (**Ver Mi cartera**), grupo `Prospectos` en **Roles y permisos**, independiente de `prospectos.ver_todos`. Migración `database/migrations/2026_11_06_ventas_mi_cartera_permiso.sql`; concesión única al rol Asesor de Ventas y revocación respetada en posteriores ejecuciones de SQL.
- Para añadir el permiso **no se cambia `RolModel.php` personalizado en Hostinger**. El listado de permisos activos se obtiene de MySQL en cada petición; se verifica que el nuevo permiso no aparece en el catálogo desactivado.

## Alcance
`index.php?controller=miCartera&action=index`
- Métricas reales de **asignaciones vigentes**, **hoy o vencidos**, **sin fecha**.
- Filtros Todos / Hoy y vencidos / Próximos / Sin programar, búsqueda nombre-teléfono-correo y 20 registros por página.
- Estado vacío informativo si no hay asignaciones; no se presentan otros prospectos como propios.
- Se muestra únicamente el **estado de la asignación**, no una etapa de embudo ni un resultado de llamada (todavía no implementados).

`index.php?controller=miCartera&action=detalle&id=N`
- Ficha de consulta acotada: nombre, contacto, asignación vigente, programas de interés, procedencias y preferencias de horario.
- Todo dato se consulta **después** de comprobar por SQL `asesor_id=usuario_id_sesion AND vigente=1 AND prospecto_id=?`.
- Un ID inexistente, de otro asesor o con asignación finalizada responde **404** igual, sin confirmar su existencia.
- No reutiliza las rutas/ficha/listado de `ProspectoController` reservados a responsables comerciales.

## Invariantes de seguridad
1. El identificador del asesor nunca procede de `GET`, formularios ni cookies: solo de la sesión autenticada y sincronizada contra MySQL por `index.php`.
2. Autorización por rol+permiso en cada acción (no depender del enlace visible).
3. Sin POST, escrituras, reasignaciones, cambio de estado, creación de resultados o llamadas desde Mi cartera.
4. Las consultas quedan restringidas a las asignaciones **vigentes**; no muestran histórico previo, usuarios vecinos ni totales generales.
5. No cambia datos existentes, telephony, Zadarma, marcación, agenda, grabaciones, resultados, notificaciones ni el dashboard del Asesor de Ventas.
6. La cuota Meta 80/día y Aliados sin límite permanecen definidas para futuras fases, no se ejecutan.

## Archivos
**Existentes, cambiados de manera mínima**
- `index.php`: nueva ruta con controlador propio.
- `app/views/layout/sidebar.php`: menú escritorio y móvil y ocultación de Prospectos global para ventas.
- `app/controllers/ProspectoController.php`: denegación explícita a rol 3 en acceso administrativo.

**Nuevos**
- `app/controllers/MiCarteraController.php`
- `app/services/MiCarteraRepository.php`
- `app/views/mi_cartera/index.php` y `detalle.php`
- `public/css/mi_cartera.css`

**Datos:** única migración independiente de `permisos` y `rol_permisos`. No hay migración de estructura para los prospectos ni nuevas asignaciones.

## Pruebas y compatibilidad
- `tests/mi_cartera_contract_test.py`: asegura segregación de menús, rutas, autorización, consultas y plantilla.
- `tests/mi_cartera_mysql_test.php`: **exclusivamente** base MySQL temporal; dos asesores, búsqueda y ficha ajena, históricos, 404 lógico, métricas, filtros, permisos y reejecución tras revocación.
- `tests/mi_cartera_release_test.py`: prueba `--check`, publicación, creación segura de carpeta de vistas, rollback, hash exacto e incompatibilidades.
- Workflow `.github/workflows/ventas-mi-cartera.yml`.

## Hostinger — no publicar sin preflight
El paquete sellado es `tools/ventas_mi_cartera_release.json`; publicador `tools/publicar_ventas_mi_cartera.py`. Solo actualiza ocho archivos listados, y comprueba tres dependencias de solo lectura (`RolModel.php` y estilos/servicio de configuración de Asesores).

1. Verificar la compatibilidad de SHA-256 de `index.php`, `app/views/layout/sidebar.php` y `app/controllers/ProspectoController.php` **instalados actualmente** contra la base del manifiesto; personalizaciones deben conciliarse, nunca sobrescribirse. Usar `--check` para detectar conflictos.
2. Realizar respaldo completo reciente de BD y de los archivos, y verificar la migración SQL. La migración es separada: dar de alta el permiso solo tras verificar el entorno en lectura y respaldo de `permisos`/`rol_permisos`.
3. Ejecutar la publicación en una sesión autorizada con `--apply --backup-root RUTA_PRIVADA` solo tras CI+preflight compatible. Para restaurar, `--restore RUTA` valida los SHA antes de tocar archivos.
4. Verificar en navegador con cuenta de Asesor de Ventas y otro asesor; menú escritorio y móvil; permiso revocado; URL del almacén administrativo; ID ajeno; y lista vacía sin datos ficticios.
5. No mezclar el despliegue con motor Meta/aliados o modificaciones de telefonía.

**Desarrollo siguiente:** motor 1B, que creará las asignaciones de Meta con máximo de 80 nuevos por asesor/día; 1C asignará Aliados en tiempo real. La cartera ya está preparada para reflejar las asignaciones vigentes que produzca ese motor.
