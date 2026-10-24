# Prospectos — Fase 1: entrega selectiva

**Para corregir la Fase 1 ya instalada:** utilizar [el paquete de correcciones](prospectos_fase1_correcciones.md). Esta página conserva las instrucciones de la instalación inicial.

Rama: `feat/prospectos-estados-fase1`, basada en la entrega vigente del PR #132. Sólo esta primera fase; revisar el funcionamiento y diseño en Hostinger antes de autorizar una fase posterior.

## Archivos operativos

- `app/controllers/ProspectoController.php`
- `app/models/ProspectoModel.php`
- `app/views/prospectos/index.php`
- `app/views/prospectos/acceso_denegado.php`
- `public/css/prospectos_fase1.css`
- `public/javascript/prospectos_fase1.js`

La portada presenta indicadores y estados activos con mapas existentes. Al seleccionar un estado se abre su tabla directamente; Todos conserva el listado general. Se mantienen los **14 campos y el filtro por aliado**, conforme a la confirmación del usuario, y las reglas Manrope V3 de Hostinger. Véase [la auditoría](prospectos_fase1_auditoria.md).

El paquete no cambia la cabecera, sidebar, estilos globales, mapas, formularios ni integraciones. Sus dependencias se comprueban, no se sobrescriben. La comprobación incluye tablas/columnas mediante una transacción de sólo lectura. La ausencia de procedencias se informa como aviso: los indicadores afectados quedan sin verificar.

## Validaciones

- 82 comprobaciones de permisos actuales y menús escritorio/móvil ejecutadas.
- 38 comprobaciones de navegador por variante visual, nativa y con tipografía V3: navegación, mapas, campos, estados vacíos/errores y contención horizontal en 1440, 1024, 768, 390 y 360px. Usan la vista y el layout reales con datos simulados; no prueban SQL.
- 13 pruebas locales del publicador ejecutadas: LF/CRLF, personalizaciones, variante exacta de Hostinger, conflictos, dependencias, idempotencia, restauración y conservación de otros módulos. El preflight de BD se simula en estas pruebas. Una prueba adicional de preflight real se reserva a un esquema sintético.
- Sintaxis PHP/JS y compatibilidad Python 3.6 verificadas.
- CI dirigido aprobado en MySQL 8.0.46 y MariaDB 10.11.19: **25 comprobaciones de BD y 35 de HTTP/navegador por motor**, además del preflight real. Fixture nueva, consultas dentro de `READ ONLY` y huella de datos antes/después. Controlador/modelo/vista/layout reales. [Ejecución verificada](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40024332232). La comprobación visual posterior en Hostinger queda pendiente de publicación selectiva y revisión por el usuario.

El arranque de los motores y la creación de los esquemas locales de prueba fueron rechazados por la revisión automática con «blocked by policy». No se reintentó esa acción local. Las capturas locales utilizan datos simulados; no se accedió ni se escribieron datos de producción.

## 1. Descargar y comprobar sin publicar

En SSH introducir el SHA completo del **commit de entrega** indicado en la conversación, que contiene el manifiesto. No utilizar el commit funcional anterior al paquete.

```bash
read -r -p "Commit completo de entrega: " REV
[[ "$REV" =~ ^[0-9a-f]{40}$ ]] || { echo "SHA no válido"; return 1 2>/dev/null || exit 1; }
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PREP="$HOME/impe-prospectos-fase1-$REV"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_prospectos_fase1.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fL "$RAW/tools/prospectos_fase1_release.json" -o "$PREP/entrega.json" &&
curl -fL "$RAW/tools/prospectos_fase1_sha256.txt" -o "$PREP/sha256.txt" &&
(cd "$PREP" && sha256sum -c sha256.txt) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos" --check
```

Revisar el diff. Exigir **REVISIÓN COMPATIBLE: 6 archivos** en la primera instalación. No forzar un conflicto ni copiar archivos completos sobre personalizaciones desconocidas. Un archivo global/mapa ausente o una dependencia obligatoria de BD incompatible detiene la entrega antes de escribir. Sin Node se verifica el SHA del JavaScript nuevo cuya sintaxis ya se validó; una versión distinta se bloquea.

## 2. Publicar sólo después de la revisión compatible

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos" --apply
```

Guardar la ruta real mostrada en `Respaldo:`. **No hay migración ni comandos SQL de escritura.**

## 3. Comprobación en Hostinger

1. Con un usuario autorizado, abrir Marketing → Prospectos. Debe iniciar en Por estados, con cuatro indicadores y los mapas del catálogo activo.
2. Buscar un estado, abrir su tarjeta y comprobar que aparece directamente su tabla. Verificar un estado con más de 25 registros y avanzar/retroceder: conservar residencia y filtros.
3. Cambiar de estado; probar otro sin registros y la pestaña Todos. Confirmar los 14 campos y el filtro de aliado previo.
4. Revisar que la fuente de tabla conserve Manrope 12px V3, escritorio/tablet/móvil, sin desplazamiento horizontal de toda la página. El desplazamiento de la tabla debe quedar dentro de su contenedor.
5. Confirmar acceso bloqueado para un usuario sin `prospectos.ver_todos`, sin concederle permisos adicionales.
6. Confirmar que un registro de prueba ya existente sigue visible. Esta entrega sólo consulta datos; no enviar formularios ni crear registros como requisito de publicación.

## Reversión opcional

Sólo si se necesita volver a los archivos anteriores; no forma parte de la instalación normal:

```bash
read -r -p "Ruta exacta mostrada en Respaldo: " RESPALDO
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos" --restore "$RESPALDO"
```

La reversión verifica las huellas de los archivos instalados antes de restaurar los originales. Si hay cambios posteriores, se detiene para no sobrescribirlos. La base de datos permanece sin cambios.
