# Prospectos: correcciones de Fase 1

Actualización sobre la Fase 1 ya instalada. Entrega: `62d16dd8bf3d1a4b67d216854e4af1a00827ef67`. Código: `4749da923da844862f7886c6e9984af982cbb175`. PR #150.

## Resultado

- Por estados cuenta y muestra los prospectos cuyo **aliado de origen pertenece al estado**. Resuelve aliados manuales, de Vinculación y vínculos históricos por sus identificadores.
- Estado y Municipio de residencia siguen mostrando lo declarado en el formulario. No se modifica ni migra ningún registro.
- Registros generales, sin aliado identificable o sin estado verificable permanecen en **Todos los prospectos**. No se deduce su territorio a partir de nombres o residencia.
- Tarjetas, búsqueda, filtro de aliado y paginación usan el mismo origen. Una procedencia repetida no duplica el prospecto; se conserva la primera procedencia de Registro.
- La navegación ya no inserta «Cargando información…» ni desplaza el contenido. Ambos buscadores tienen un único foco visible en su contenedor.
- Se conservan los 14 campos, permisos actuales y personalizaciones Manrope V3.

## Instalar en SSH

### 1. Descargar y revisar sin publicar

Copiar el bloque completo. Las tres huellas están fijadas a los bytes publicados y verificadas contra GitHub.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
REV="62d16dd8bf3d1a4b67d216854e4af1a00827ef67"
PREP="$HOME/impe-prospectos-correcciones-$REV"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
mkdir -p -m 700 "$PREP" &&
curl -fsSL "$RAW/tools/publicar_prospectos_fase1.py" -o "$PREP/publicar.py" &&
curl -fsSL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fsSL "$RAW/tools/prospectos_fase1_release.json" -o "$PREP/entrega.json" &&
(cd "$PREP" && sha256sum -c <<'SHA256'
686a39160f03672c059d389ab63bec375fef368635165bf9cb829581efc4b7c1  publicar.py
da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar_aliados_registro.py
5f82d8b0cb8aa8f59ef4dc156573f24c6acb857c6d025cc6126df25e9f3d0813  entrega.json
SHA256
) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-correcciones" --check
```

Las huellas deben decir **OK** y la revisión debe terminar como **REVISIÓN COMPATIBLE**, sin conflictos. El paquete verifica seis archivos; normalmente cambiarán cinco, porque la vista de acceso denegado sigue igual. Comprueba dependencias de BD mediante lecturas. No ejecuta SQL de escritura. Si falta una dependencia o hay un conflicto, no continuar con el siguiente bloque.

### 2. Aplicar la actualización compatible

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-correcciones" --apply
```

Guardar la ruta que aparece después de `Respaldo:`. Abrir Prospectos y recargar con **Ctrl + F5**.

## Pruebas en el sitio

1. Alternar entre Por estados y Todos. El contenido no debe bajar para mostrar un aviso de carga.
2. Hacer clic y usar Tab en Buscar estado y Nombre o teléfono: un solo foco discreto alrededor del buscador, sin rectángulo interior.
3. Elegir un prospecto existente cuyo aliado y residencia estén en estados diferentes. Debe aparecer en el estado del **aliado**, y las columnas de residencia deben conservar los datos originales.
4. Comparar el número de la tarjeta con el total de su tabla. Probar búsqueda, aliado y siguiente página cuando corresponda.
5. En Todos, confirmar que siguen los registros generales y los que no tienen un territorio de aliado verificable. El total global no debe disminuir.

No es necesario crear prospectos para estas pruebas; se pueden usar registros existentes.

## Reversión opcional

Sólo si se necesita deshacer esta actualización:

```bash
read -r -p "Ruta exacta mostrada en Respaldo: " RESPALDO
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-correcciones" --restore "$RESPALDO"
```

La restauración comprueba que los archivos no hayan cambiado después. No modifica datos.

## Validación

- 41 comprobaciones visuales en cada variante: original y con tipografía V3 (82 en total), en 1440, 1024, 768, 390 y 360px. Vista y layout reales, datos simulados.
- 82 comprobaciones de permisos existentes; sin asignaciones nuevas.
- 14 pruebas del publicador: 13 ejecutadas localmente y preflight real reservado a CI. Incluyen personalizaciones, CRLF, conflicto, idempotencia y restauración.
- CI aprobado en MySQL 8.0.46 y MariaDB 10.11.19: **30 comprobaciones de BD y 38 de HTTP/navegador por motor**, con consultas de sólo lectura y huella de datos antes/después. [Ejecución del código](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40116841782).

La publicación y las pruebas con datos reales de Hostinger quedan a cargo del usuario mediante estos bloques. Este paquete no cambia formularios públicos, ligas, QR, Aliados ni Convenios.
