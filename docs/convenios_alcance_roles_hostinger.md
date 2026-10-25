# Convenios: alcance territorial por rol

Convenios sólo permitía cobertura nacional a Administrador y Marketing. Por eso Dirección de Operaciones veía el selector vacío aunque tuviera permiso para entrar. La consulta corregida aplica asignaciones únicamente a los roles históricos Asesor (3), Analista de Datos (4) y Cuenta Clave (6). Los demás roles con permiso de consulta ven los 32 estados, incluidos aquellos sin convenios.

La entrega reemplaza sólo `app/controllers/CrearConvocatoriaController.php`. Conserva los endpoints de borradores y QR, el bloque personalizado de convenios internacionales y los permisos de acceso/publicación. No modifica datos, modelos, helpers, estilos ni telefonía.

## Instalar

Pega el bloque completo en SSH. Descarga dos archivos verificados a una carpeta privada, comprueba compatibilidad, respalda y aplica el controlador. `set -eu` detiene el bloque si una verificación falla. No requiere migración SQL.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-convenios-alcance-8d07c00b"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/8d07c00bb32d26e2e915d5f124c7928426aafe5e/tools"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
for f in publicar_convenios_alcance_roles.py convenios_alcance_roles_release.json; do
  curl -fsSL "$BASE/$f" -o "$f"
done
printf '%s\n' \
  '1c06fd616c9df808f69db6eb1e845abc79db5df7004bc6570d9230aa60316583  publicar_convenios_alcance_roles.py' \
  '0c2993fba4f5d27d16ccda282cfd0125773f88b70327abbda228593f3ec06958  convenios_alcance_roles_release.json' | sha256sum -c -
python3 publicar_convenios_alcance_roles.py \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-convenios-alcance" --check
python3 publicar_convenios_alcance_roles.py \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-convenios-alcance" --apply
)
```

El resultado debe terminar con `APLICADO. RESPALDO: ...`, o `SIN CAMBIOS` si ya estaba instalado. Un `CONFLICTO` deja intacto el controlador y requiere conciliar su nueva versión; no fuerces una copia completa del repositorio. El publicador acepta la variante instalada cuyo SHA-256 normalizado es `27da237204d077fe63aa55ff9cd6f7f63b7a0d9714ae17b72c39a02048fff224`.

## Comprobar

1. En la cuenta de Director de Operaciones, recarga Convenios sin filtros. Deben aparecer los 32 estados tanto en las tarjetas como en el selector. Basta el permiso **Ver convenios**; **Gestionar convenios** también permite entrar. No se necesita asignar territorios.
2. Abre un estado con convenios: se muestran sus instituciones y fichas. Abre otro sin convenios: permanece disponible y muestra el estado vacío correspondiente.
3. Una cuenta de Finanzas u otro rol no territorial con **Ver convenios** también consulta los 32 estados.
4. Cuenta Clave, Asesor y Analista sólo consultan sus asignaciones vigentes. Sin asignaciones no reciben convenios de otros territorios, incluso con una dirección de estado escrita manualmente.
5. Para probar una revocación, quita tanto **Ver convenios** como **Gestionar convenios**, recarga y comprueba la denegación. Marketing conserva su acceso existente por **Ver convocatorias**, si lo tiene activado.

Consultar convenios no concede automáticamente permiso para crear convocatorias. La publicación mantiene sus permisos y controles existentes.

Validación automatizada: controlador real con modelos aislados para cinco perfiles nacionales, tres tipos de asignación, ausencia de asignaciones, datos de otro estado, revocación y publicación independiente. Se probaron además integridad del paquete, CRLF, versión desconocida, idempotencia y restauración exacta. La aceptación visual final se comprueba en Hostinger después de aplicar.

## Restaurar

Utiliza la ruta exacta de respaldo impresa al aplicar:

```bash
python3 "$HOME/impe-convenios-alcance-8d07c00b/publicar_convenios_alcance_roles.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-convenios-alcance" \
  --restore "$HOME/respaldos-impe-convenios-alcance/CARPETA-REAL"
```

La restauración no sobrescribe ediciones posteriores del controlador.
