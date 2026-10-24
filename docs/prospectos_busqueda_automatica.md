# Prospectos: búsqueda automática

Actualización sobre la corrección territorial de Fase 1 ya instalada. PR #151. Entrega: `3c4266a1da07ae6d0847d556efba16251f887667`. Código: `16caa746ec6336e29d457271dfca60da40494aaf`.

## Comportamiento

- Buscar estado filtra las tarjetas al escribir; se elimina su botón Buscar.
- El buscador del listado acepta **nombre, teléfono o correo**, completo o parcial, en Todos y dentro de cada estado del aliado.
- Consulta automáticamente tras una pausa de 350 ms y actualiza tabla, total y paginación sin recargar la pantalla ni perder el foco. No hay botón Buscar.
- Cambiar Aliado de origen aplica el filtro automáticamente. Borrar la búsqueda recupera el conjunto del ámbito y aliado seleccionados.
- Consulta todo el conjunto desde PHP, incluyendo registros fuera de la página visible. Una nueva búsqueda reinicia la paginación, mantiene el estado del aliado y descarta respuestas atrasadas.
- Ante un fallo se conserva la tabla y se informa que no pudo actualizarse; Enter permite reintentar. Se mantienen los formularios GET y enlaces de paginación.
- Conserva 14 columnas, permisos, tipografía V3 y datos. No requiere migración.

## Instalar en SSH

### 1. Descargar y revisar sin publicar

Copiar el bloque completo. Las tres huellas están fijadas a los bytes publicados y verificadas contra GitHub.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
REV="3c4266a1da07ae6d0847d556efba16251f887667"
PREP="$HOME/impe-prospectos-busqueda-$REV"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
mkdir -p -m 700 "$PREP" &&
curl -fsSL "$RAW/tools/publicar_prospectos_fase1.py" -o "$PREP/publicar.py" &&
curl -fsSL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fsSL "$RAW/tools/prospectos_fase1_release.json" -o "$PREP/entrega.json" &&
(cd "$PREP" && sha256sum -c <<'SHA256'
686a39160f03672c059d389ab63bec375fef368635165bf9cb829581efc4b7c1  publicar.py
da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar_aliados_registro.py
511185f1ada9e7215e6a323a6ec6280247b3563150a9251baea6145ad11bb18c  entrega.json
SHA256
) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-busqueda" --check
```

Las huellas deben decir **OK** y la revisión debe terminar como **REVISIÓN COMPATIBLE**, sin conflictos. El paquete verifica seis archivos; normalmente cambiarán cuatro: modelo, vista, CSS y JavaScript. Comprueba dependencias de BD mediante lecturas. No ejecuta SQL de escritura. Si falta una dependencia o hay un conflicto, no continuar con el siguiente bloque.

### 2. Aplicar la actualización compatible

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-busqueda" --apply
```

Guardar la ruta que aparece después de `Respaldo:`. Abrir Prospectos y recargar con **Ctrl + F5**.

## Pruebas en Hostinger

1. Abrir Por estados, escribir parte del nombre de un estado y comprobar que las tarjetas se filtran sin botón Buscar.
2. Abrir Todos los prospectos, escribir un correo completo o un fragmento y esperar un momento: deben actualizarse total y tabla, manteniendo el cursor en el buscador.
3. Probar un nombre y un teléfono. Borrar el texto: vuelve el conjunto completo.
4. Elegir un aliado: se aplica inmediatamente; combinarlo con un correo y limpiar después. Se conserva el aliado elegido.
5. Repetir dentro de un estado. Los resultados deben pertenecer a aliados de ese estado, aunque la residencia del prospecto sea otra.
6. Si existen varias páginas, ir a la segunda y buscar un correo: vuelve a la primera página del resultado filtrado. La paginación conserva la búsqueda al avanzar.

Usar registros existentes; no hace falta crear prospectos para comprobarlo.

## Reversión opcional

Sólo si se necesita deshacer esta actualización:

```bash
read -r -p "Ruta exacta mostrada en Respaldo: " RESPALDO
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-prospectos-busqueda" --restore "$RESPALDO"
```

La restauración comprueba que los archivos no hayan cambiado después. No modifica datos.

## Validación

- 63 comprobaciones locales de navegador por variante, original y Manrope V3 (126 en total): búsqueda automática por los tres campos, ámbito territorial, filtro de aliado, reinicio de página, foco conservado, respuestas fuera de orden, error/reintento y diseño de 360 a 1440px. Vista/layout reales con datos simulados.
- 82 comprobaciones de permisos existentes.
- 13 pruebas locales del publicador; incluyen preservación de personalizaciones y CRLF, conflictos, idempotencia y restauración. Preflight real reservado al esquema sintético de CI.
- CI aprobado en MySQL 8 y MariaDB 10.11: **36 comprobaciones DB y 60 de HTTP/navegador por motor**, con controlador, modelo, vista y layout reales, esquema sintético y huella de datos antes/después. También pasó el preflight real del publicador. [Ejecución verificada](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40117885537).

Las tres huellas de descarga se verificaron contra los bytes de GitHub. La publicación en Hostinger queda pendiente de ejecutar los bloques anteriores.
