# Entrega 1A.2 — Foto de perfil, horarios compactos y varios programas

**Base:** PR #235 (configuración 1A ya publicada en Hostinger). Esta entrega tiene su propio PR y no modifica los archivos del release anterior.

## Comportamiento

- Lista exclusivamente usuarios existentes con rol `Asesor de Ventas`; lee su `foto_perfil` real, con el mismo `AvatarHelper` que `Usuarios`. Si no hay foto o está dañada, deja las iniciales.
- En la ficha del asesor se muestra `Programas que atiende` con selección múltiple. `Todos` en Titulación marca todas las modalidades vigentes detectadas por nombre; igual en Bachillerato. `Otros programas` acepta futuros programas (por ejemplo Inglés) tan pronto estén activos en `formulario_programas`, sin nuevos cambios de esquema.
- Se puede combinar Bachillerato + Titulación + Inglés, o seleccionar solo un programa de cualquiera. Los programas **son IDs reales del catálogo**: no se crean programas ficticios.
- Jornada medio tiempo/completo y disponibilidad como antes.
- Horarios compactos en cuadrícula de dos columnas en escritorio, una en pantallas estrechas.
- Solo presentación y configuración administrativa. No asigna, redistribuye, elimina ni modifica prospectos, resultados, seguimientos o telefonía.

## Persistencia y compatibilidad

Nueva tabla aditiva `prospecto_asesores_programas` con clave compuesta (`usuario_id`,`programa_codigo`) y claves foráneas al asesor configurado y al catálogo existente. No cambia las tres tablas de 1A ni su estructura.

La migración `database/migrations/2026_11_06_prospectos_asesores_multiprograma.sql` copia el `programa_codigo` previo de cada asesor **solo si aún no tiene filas** en la tabla nueva. Una repetición de la migración no restaura programas que el administrador haya quitado.

Al guardar:
1. Valida permiso y CSRF del controlador ya publicado.
2. Valida todos los códigos contra `formulario_programas.activo=1`. Requiere al menos uno.
3. Bloquea el usuario/configuración con `FOR UPDATE`; verifica versión para evitar sobrescritura concurrente.
4. Actualiza transaccionalmente las relaciones N-a-N, jornada y horarios.
5. Mantiene `prospecto_asesores_config.programa_codigo` como referencia de compatibilidad histórica (primer código ordenado); **el futuro motor de reparto DEBE usar todos los códigos en `prospecto_asesores_programas`**.
6. Audita selección anterior y posterior de todos los códigos.

No hay lógica de reparto en esta entrega: Meta sigue definido como máximo 80 nuevos diarios por asesor; Aliados sin límite. No se modifica ni aplica esa cuota aquí.

## Publicación segura de Hostinger

**No publicar antes de respaldo y pruebas aprobadas.**

- El release incremental cambia exclusivamente `app/services/ProspectoAsesorConfiguracionService.php`, `app/views/prospectos/partials/configuracion_asesores.php`, `public/css/prospectos_asesores.css`, `app/views/prospectos/central.php` y agrega `public/javascript/prospectos_asesores.js`.
- No cambia `app/controllers/ProspectoController.php`; se comprueba su SHA-256 como dependencia, junto a `public/css/prospectos_central.css` y el `RolModel.php` personalizado de Hostinger.
- El manifiesto y publicador son `tools/prospectos_asesores_multiprograma_release.json` y `tools/publicar_prospectos_asesores_multiprograma.py`. `--check` no escribe; `--apply --backup-root` respalda originales fuera de `public_html`; `--restore RUTA` comprueba SHA de destino antes de revertir.
- Antes de `--apply` comprobar que `app/helpers/AvatarHelper.php` está disponible con la API actual, y que la migración está instalada. Antes de SQL, respaldo completo de la base, preflight de tablas, registro e integridad de la migración.
- En caso de cualquier `CONFLICTO`, detenerse sin reemplazar modificaciones de Hostinger. No reejecutar la migración 1A de permisos ni sus backups por esta entrega.

## Pruebas

- `php tests/prospectos_asesores_config_unit.php`: combina códigos, rechaza inexistentes y protege jornadas.
- `php tests/prospectos_asesores_config_db.php`: base MySQL **desechable**, herencia del programa anterior, selección de Bachillerato+Titulación+Inglés, sustitución/retirada, auditoría, versión, roles, revocación de permisos, doble migración.
- `python3 tests/prospectos_asesores_multiprograma_ui_test.py`: contrato UI.
- `node --check public/javascript/prospectos_asesores.js`.
- `python3 tests/prospectos_asesores_multiprograma_release_test.py`: paquete sellado, checks, apply, restore, rechazo de SHA desconocidos, sin SQL.
- GitHub Actions: `.github/workflows/prospectos-asesores-multiprograma.yml`.

Falta prueba manual en navegador real de foto modal, acordeón, selección de grupos, horarios y persistencia después de despliegue controlado.
