# Corrección urgente: Aliados, Convenios y Cuenta Clave (2026-10-22)

PR #124; rama `feat/aliados-programas-registro`. Fuente inmutable de ocho archivos:
`a5da21270d57d737f2debed9074a906d3123ead5`.

## Causa y corrección

- Marketing veía Aliados vacío porque se le aplicaba `obtenerEstadosSupervisadosCuentaClave()` sin asignación de Cuenta Clave. Ahora tiene cobertura nacional (32 estados activos) únicamente al contar con `aliados.ver`.
- Convenios consultaba las 32 entidades para cualquier perfil con `convenios.ver`; ahora Cuenta Clave obtiene sólo asignaciones activas y vigentes, y la consulta de instituciones se acota **antes** de su `LIMIT 500`, tanto para institucionales como manuales.
- `ConvocatoriaController::index()` y `AliadoRegistroService::opcionesConvocatoria()` exigían rol Marketing, bloqueando Cuenta Clave. Ahora, para ese rol, crear requiere `convenios.ver`, `convocatorias.ver` y `convocatorias.crear`; además, el servidor valida la asignación vigente del territorio y origen reales del aliado. El permiso `Ver convenios` no autoriza por sí solo crear.
- El nombre de estado en la URL se ignora como fuente de verdad al venir de Convenios. Si `estado_id` y `aliado_id` no corresponden en la base de datos, se rechaza la operación sin publicar ni modificar datos.
- Marketing conserva `formularios_aliados.gestionar_enlaces`. Cuenta Clave puede publicar con un enlace existente **ya habilitado**, no habilitarlo ni generarlo por su cuenta; si falta, debe gestionarlo Marketing.

## Comprobación y despliegue selectivo en Hostinger

No usar `git pull`, no ejecutar SQL, no sustituir carpetas completas ni sobrescribir conflictos. Ejecutar en SSH:

```bash
set -e
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
ENTREGA="$HOME/entregas-impe/correccion-acceso-20261022"
REV="4be08498124998d4a2247147fd255cf31cefa210"
mkdir -p "$ENTREGA"
curl -fLsS "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV/tools/publicar_alcance_aliados_convenios_20261022.py" -o "$ENTREGA/publicar.py"
curl -fLsS "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV/tools/alcance_aliados_convenios_20261022_release.json" -o "$ENTREGA/entrega.json"
python3 -m py_compile "$ENTREGA/publicar.py"
python3 "$ENTREGA/publicar.py" --site "$SITIO" --manifest "$ENTREGA/entrega.json" --check
```

Revisar diff, que diga `REVISIÓN COMPATIBLE` y no reporte `CONFLICTO`; ninguna acción SQL. Si el sitio tiene personalizaciones superpuestas, pedir la conciliación puntual sin forzar el archivo de GitHub.

Publicar **sólo** si los ocho archivos son compatibles y aprobaron verificaciones:

```bash
python3 "$ENTREGA/publicar.py" --site "$SITIO" --manifest "$ENTREGA/entrega.json" --apply
python3 "$ENTREGA/publicar.py" --site "$SITIO" --manifest "$ENTREGA/entrega.json" --check
```

Guardar la ruta completa que diga `PUBLICADO. Respaldo:`. Restauración selectiva (sin revertir SQL, y sólo si no hubo modificaciones posteriores):

```bash
RESPALDO="/ruta/completa/mostrada-en-PUBLICADO"
python3 "$ENTREGA/publicar.py" --site "$SITIO" --manifest "$ENTREGA/entrega.json" --restore "$RESPALDO"
```

## Pruebas obligatorias

1. Reiniciar sesión y confirmar `aliados.ver` para Marketing; Aliados debe mostrar 32 entidades mexicanas activas, y navegación a cualquiera.
2. Cuenta Clave sólo ve sus estados vigentes en Convenios, sin filas de otros estados al manipular `?estado_id=`.
3. Cuenta Clave con sólo `convenios.ver` consulta, pero no ve botón Crear convocatoria.
4. Cuenta Clave con `convenios.ver`, `convocatorias.ver` y `convocatorias.crear` accede al editor desde convenio de su propio estado y con un enlace ya preparado por Marketing.
5. Usar la URL de ejemplo con `territorio_id=22`, `estado_nombre=Querétaro`: verificar el ID real de estado y aliado. Si no coinciden o queda fuera de territorio, debe rechazarlo.
6. Marketing mantiene Gestión, QR, Registro, Compartir y cobertura global.
7. Cambiar ID del aliado o territorio en la petición POST: debe rechazar origen ajeno sin guardar nada.
8. Regresión Handly, Aliados internacionales, Prospectos y perfiles restantes.

Sin cambios de estructura SQL, sin publicación directa desde GitHub/Codex. CI de la revisión debe comprobarse separadamente.
