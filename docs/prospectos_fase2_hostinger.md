# Prospectos — Fase 2: aliados y programas

Rama: `feat/prospectos-filtros-fase2`. Código: `a464e0292d683697fb0e684400f0d42c6b1ea962`. Entrega selectiva: `d173d92bf1b598050dd51f71e9ca95955b77af2b`. [PR #153](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/153).

La base fue contrastada con las tres ramas solicitadas y los cinco hashes de Hostinger antes de desarrollar. La vista instalada conserva Manrope V3. Véase [la auditoría previa](prospectos_fase2_auditoria.md).

## Comportamiento

Un solo bloque presenta Buscar prospecto, Aliado de origen y Programa educativo. Los tres se combinan con el estado del aliado, se aplican automáticamente y actualizan la tabla sin salir de ella. Se mantienen la búsqueda por nombre/apellidos/móvil/correo, 14 columnas, páginas de 25 y el orden descendente por fecha e ID.

Los aliados del selector proceden de los registros del ámbito y se contrastan con `formulario_aliados.id`; se utiliza su nombre actual y se añade el ID para distinguir nombres repetidos. Registro general exige ausencia de procedencia de tipo registro. No se utiliza Inscripción como procedencia de Registro.

Los programas del selector se obtienen del estado y aliado elegidos, sin restringir el catálogo por el texto buscado o por el programa activo. Esto permite combinar filtros y ver un estado vacío sin que desaparezcan las otras opciones.

- **Aliados:** el valor real de `formulario_aliado_procedencias.programa_codigo`. `formulario_programas` aporta el nombre cuando existe; si falta el catálogo, se identifica expresamente por su código.
- **Registro general:** el valor realmente almacenado en `formulario_registros.perfil_interes`. Las opciones llevan el prefijo Registro general y se distinguen de los códigos de aliados, aunque tengan el mismo nombre. Se comparan valores exactos, sin equivalencias inventadas.
- Se conservan programas históricos, aunque ya no estén activos o autorizados en `formulario_aliado_programas`. Esa tabla describe la oferta actual y no debe borrar la procedencia histórica de un prospecto.

Los filtros viajan en una URL reproducible. Cambiar aliado conserva el texto y el estado; un programa incompatible vuelve a Todos los programas. Cada filtro reinicia a página 1; la paginación conserva los cuatro criterios. Limpiar filtros conserva el estado o la vista nacional y sólo aparece cuando hay filtros activos.

Los selectores reutilizan el acabado institucional, incluyen búsqueda, teclado y una lista acotada a 40 coincidencias. No hay pantallas intermedias. Sin JavaScript permanecen los selects nativos y un botón Aplicar filtros en el mismo formulario GET. No se alteran permisos: se mantiene sesión y `prospectos.ver_todos`.

## Archivos operativos

1. `app/controllers/ProspectoController.php`: validación y conservación de programa aplicado.
2. `app/models/ProspectoModel.php`: opciones por ámbito, filtros preparados, total y páginas.
3. `app/views/prospectos/index.php`: tres filtros, contador, limpieza y alternativa sin JavaScript.
4. `public/javascript/prospectos_fase1.js`: actualización de opciones/resultados y selectores buscables.
5. `public/css/prospectos_fase1.css`: distribución y estilos encapsulados.

El manifiesto también verifica `app/views/prospectos/acceso_denegado.php`, cuyo contenido no cambia. Los estilos comunes y layouts se verifican como dependencias; no se sobrescriben. Se añaden pruebas, auditoría y herramientas de entrega independientes. No hay migración ni SQL de escritura.

## Instalar en SSH

### 1. Descargar y revisar sin publicar

Copiar el bloque completo. Las tres huellas están fijadas a los bytes publicados y verificadas contra GitHub.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
REV="d173d92bf1b598050dd51f71e9ca95955b77af2b"
PREP="$HOME/impe-prospectos-fase2-$REV"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
mkdir -p -m 700 "$PREP" &&
curl -fsSL "$RAW/tools/publicar_prospectos_fase2.py" -o "$PREP/publicar.py" &&
curl -fsSL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fsSL "$RAW/tools/prospectos_fase2_release.json" -o "$PREP/entrega.json" &&
(cd "$PREP" && sha256sum -c <<'SHA256'
48abcec90b9cc62c4a7a7ecb6531cfe5cbbd56f330d2b4b08e3af0b603bc6218  publicar.py
da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar_aliados_registro.py
22dca3076ceba1df2d93b9f25ff8a99d4d591b6af26c040a255da38eae36a619  entrega.json
SHA256
) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-fase2" --check
```

Las huellas deben decir **OK** y la revisión debe terminar como **REVISIÓN COMPATIBLE**, sin conflictos. El paquete verifica seis archivos; normalmente cambiarán cinco: controlador, modelo, vista, CSS y JavaScript. Comprueba dependencias de BD mediante lecturas. No ejecuta SQL de escritura. Si falta una dependencia o hay un conflicto, no continuar con el siguiente bloque.

### 2. Aplicar la actualización compatible

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-fase2" --apply
```

Guardar la ruta que aparece después de `Respaldo:`. Abrir Prospectos y recargar con **Ctrl + F5**.

## Pruebas en Hostinger

Usar prospectos existentes; no crear o modificar registros como requisito de instalación.

1. Abrir un estado desde sus tarjetas. Comprobar los tres filtros con la misma altura, tabla de 14 campos, indicadores y mapas existentes.
2. Localizar un aliado escribiendo en su selector. Si hay nombres duplicados, verificar el ID y que sus prospectos no se mezclen.
3. Seleccionar un aliado y un programa: comprobar que sólo aparecen sus prospectos. Combinar nombre, apellidos, teléfono o correo. El total debe coincidir con la tabla.
4. Cambiar de aliado: deben actualizarse los programas; una opción incompatible vuelve a Todos, conservando texto y estado.
5. En Todos, seleccionar Registro general. Los programas deben aparecer como perfiles generales, sin atribuirles códigos de aliados.
6. Con varias páginas, avanzar y comprobar que estado, aliado, programa y texto permanecen en la URL. Cambiar un filtro debe volver a página 1.
7. Limpiar filtros: conservar estado o vista Todos y ocultar la acción cuando no queden filtros activos.
8. Probar una combinación sin resultados y escritura rápida. No deben reaparecer respuestas antiguas ni errores crudos.
9. Revisar escritorio y móvil, desplegables buscables y navegación con Tab/flechas/Enter/Escape.
10. Si se desea comprobar la alternativa, desactivar JavaScript temporalmente y usar Aplicar filtros y Limpiar filtros. Rehabilitarlo después.

## Reversión opcional

Sólo si se necesita deshacer esta actualización:

```bash
read -r -p "Ruta exacta mostrada en Respaldo: " RESPALDO
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-fase2" --restore "$RESPALDO"
```

La restauración comprueba que los archivos no hayan cambiado después. No modifica datos.

## Validación e integridad

- Auditoría previa de ramas y cinco hashes de producción completada; CSS/JS públicos comprobados además por descarga de sus bytes.
- 92 comprobaciones locales de navegador por variante (original y Manrope V3: 184 en total) sobre vista/layout reales y datos simulados. Incluyen combinaciones, nombres duplicados, programas dinámicos, limpieza, paginación, peticiones atrasadas, accesibilidad por teclado, formulario sin JavaScript, error de programas y responsive de 360 a 1440px.
- 82 comprobaciones de permisos existentes aprobadas.
- 13 pruebas locales del publicador aprobadas; otra comprueba el preflight real en CI. Personalizaciones, Manrope V3, CRLF, conflictos, idempotencia, respaldo y restauración.
- CI aprobado en MySQL 8.0.46 y MariaDB 10.11.19: **71 comprobaciones de BD por motor** (36 de regresión y 35 específicas de Fase 2), **88 de HTTP/navegador por motor**, permisos y preflight real del publicador. [Ejecución Fase 2](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40121336102). También pasó [la ejecución independiente de regresión de Fase 1](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40121336091).
- Las consultas se ejecutaron en READ ONLY y la huella de todas las tablas sintéticas coincidió antes y después. Se probó además recuperación de catálogo ausente/incompatible sin modificar registros; el esquema sintético quedó restaurado. No se accedió a datos reales de producción.

Las huellas de descarga del paquete se verificaron contra los bytes de GitHub. La base de archivos coincide con los cinco hashes suministrados. La estructura real de BD de Hostinger se verificará al ejecutar --check; una incompatibilidad detiene el publicador antes de escribir. Los datos de Hostinger no se han escrito ni modificado; sus comprobaciones visuales y funcionales posteriores a la publicación quedan pendientes del usuario.

Esta entrega termina en **Fase 2**. No incluye exportaciones, detalle individual, cambios de columnas, filtros de fechas, relaciones prospecto-convocatoria ni cambios en formularios públicos, enlaces, QR u otros módulos. Fase 3 queda pendiente de revisión y autorización del usuario.
