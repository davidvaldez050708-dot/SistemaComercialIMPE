# Desempeño de asesores sin filtro territorial

Entrega: `c959c06cffc38915244f5ae1f1e433354819c99d`.

El filtro anterior seleccionaba asesores por asignación vigente, sin conocer el estado de los contactos realizados. Esta actualización lo retira de Desempeño de asesores y de su PDF. Las consultas usan periodo y asesor; los enlaces antiguos con `estado_id` también ignoran ese parámetro para evitar filtros invisibles. Los datos no se presentan como actividad por estado.

Dirección y Administración conservan el acceso a todos los asesores autorizados, Cuenta Clave a su equipo explícito y cada asesor a su actividad. Esos controles de acceso se mantienen aunque no haya un filtro territorial. El filtro de territorio del área de Analistas continúa funcionando.

## Aplicación

Requiere el ranking instalado de la entrega `a8e02fa5`. Sólo actualiza cuatro archivos, comprueba las versiones instaladas y crea respaldo privado. No ejecuta SQL ni cambia permisos o telefonía.

Pegar este bloque completo en SSH:

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-desempeno-sin-territorio-c959c06c"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/c959c06cffc38915244f5ae1f1e433354819c99d"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_desempeno_asesores_sin_territorio.py" -o publicar_desempeno_asesores_sin_territorio.py
curl -fsSL "$BASE/tools/desempeno_asesores_sin_territorio_release.json" -o desempeno_asesores_sin_territorio_release.json
printf '%s\n' \
'ea1f62349e85dfdbdbdb4964375c1673c39d93cf6bd7e768cf9857e9ce2e9e78  publicar_desempeno_asesores_sin_territorio.py' \
'c984d7864c9e107f02e199dc542c87aa56de3461042a9a234b875d04b452aec3  desempeno_asesores_sin_territorio_release.json' | sha256sum -c -
python3 publicar_desempeno_asesores_sin_territorio.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-desempeno-sin-territorio" --check
python3 publicar_desempeno_asesores_sin_territorio.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-desempeno-sin-territorio" --apply
)
```

Tras **APLICADO**, recargar Desempeño y seleccionar Asesores. El selector territorial desaparece en esa área. La consulta y el PDF conservan el mismo conjunto autorizado de llamadas.

Para restaurar, sustituir el último argumento por la ruta exacta de respaldo que mostró la aplicación:

```bash
python3 "$HOME/impe-desempeno-sin-territorio-c959c06c/publicar_desempeno_asesores_sin_territorio.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-desempeno-sin-territorio" \
  --restore "$HOME/respaldos-impe-desempeno-sin-territorio/REEMPLAZAR_POR_EL_RESPALDO_GENERADO"
```

## Verificación

Se comprueba que el selector territorial no se muestre ni se envíe en Asesores, que permanezca en Analistas, que los enlaces antiguos no cambien el ranking y que se mantengan los bloqueos de personas ajenas. La exportación presenta participantes y alcance, sin atribución territorial. Se conservan las cifras y criterios del índice.
