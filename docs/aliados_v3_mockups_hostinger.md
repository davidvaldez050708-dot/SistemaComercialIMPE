# Correcciones V3 — Aliados, Convenios y Convocatorias por programa

> Instalación selectiva, reversible y sin sobrescribir el modal `crear_modal.php`, JavaScript compartido de Hostinger, Inscripción ni Telefonía.

## Alcance técnico

- **Aliados manuales:** unificar modal de Canales del aliado con el de Vinculación. `telefono_fuente` se usa solo como fuente de referencia; seleccionar «Usar para difusión» no modifica el teléfono original.
- **WhatsApp:** separar confirmado, autorización expresa y preferido. Nunca asumir consentimiento por tener un número.
- **Convenios:** reducir filtros, quitar contador redundante y doble flecha territorial; cambiar tabla por listado responsivo agrupado por municipio, con badges informativos y botón explícito de acción.
- **Detalle del convenio:** normalizar el espaciado de Origen, notas y programas al diseño del CRM.
- **Gestión de convocatorias:** agregar modal de un bloque por programa autorizado. Cada bloque tiene título, fechas, disponibilidad e imagen únicos, y guarda una convocatoria **real**. El enlace de Registro y QR son estables por aliado y compartidos por todos los programas.
- **Relación de origen:** cada registro nuevo se vincula a `marketing_aliado_convocatorias_origen` con origen manual/institucional y código del programa, sin crear seguimientos ficticios.
- **Atomicidad:** la creación multiprograma usa una transacción común; si una publicación falla, se revierten todas y se retiran imágenes nuevas.

## Comprobación de compatibilidad en Hostinger

Revisión inmutable del publicador: `fac69ab2eb292e9abbd2f92be6294cc9878839e2` (corrección Python 3.6 y conciliación puntual de ConvocatoriaController.php, ConvocatoriaModel.php y callback internacional PR114).
El publicador trae 13 archivos desde el commit `7f3548dd8600a83b329c4c68139b0e396d3aea3e` y verifica cada blob SHA. Conserva todos los archivos originales en respaldo privado y ejecuta `php -l`; usa `node --check` cuando Node está disponible. Si encuentra modificaciones personalizadas incompatibles, aborta.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/entregas-impe/aliados-v3"
curl -fLsS --proto '=https' --tlsv1.2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/fac69ab2eb292e9abbd2f92be6294cc9878839e2/tools/publicar_ajustes_aliados_v3.py" \
  -o "$HOME/entregas-impe/aliados-v3/publicar_ajustes_aliados_v3.py"
test "$(git hash-object "$HOME/entregas-impe/aliados-v3/publicar_ajustes_aliados_v3.py")" = "80b8a6bf2187bdae79d7d528801ef75eac2283ee" &&
python3 -m py_compile "$HOME/entregas-impe/aliados-v3/publicar_ajustes_aliados_v3.py" &&
python3 "$HOME/entregas-impe/aliados-v3/publicar_ajustes_aliados_v3.py" --check --site "$SITIO"
```

El ajuste del modelo exige seis sustituciones exactas y reversibles dentro de `crear()`. La variante revisada de PR114 con `?callable $relacionar = null` se concilia sin eliminar la función de asociación internacional; un tercer argumento booleano se reserva para el lote V3. Si Hostinger tiene personalizaciones en cualquiera de esos seis fragmentos, se detiene y no toca el sitio; las demás personalizaciones se conservan byte por byte. Nunca cambiar manualmente ese método para forzar la instalación. Si la declaración de `crear()` difiere de las firmas reconocidas, `--check` muestra solo su firma, sin copiar lógica de producción.

**Antes de `--apply`**: CI de PR #124 completamente verde; respaldo reciente completo de MySQL fuera de `public_html`, además del respaldo de archivos de la versión anterior.

Después de `--apply`, la instalación de los atributos nuevos **exige dos migraciones adicionales y un respaldo SQL completo**. No modificar columnas existentes.

```bash
php "$SITIO/tools/migrar_canales_manuales_20261022.php" --check
php "$SITIO/tools/migrar_convocatorias_multiprograma_20261022.php" --check
```

Cuando `--check` indique solo `PENDIENTE`, con respaldos verificados, ejecutar `--apply --respaldo-confirmado` en cada herramienta y luego ambos `--check` nuevamente.

El publicador tiene restauración selectiva `--restore RUTA_RESPALDO --site "$SITIO"`. La restauración de archivos **no elimina** tablas nuevas ni registros; revisar respaldos SQL antes de cualquier restauración de datos.

## Verificación funcional obligatoria en producción

1. En **Aliados**, abrir Canales del aliado para un registro manual y confirmar que aparece el teléfono original, y que «Usar para difusión» permite guardarlo sin alterar ese dato fuente. Comprobar los tres estados WhatsApp por separado.
2. En el listado territorial de **Convenios**, validar filtros, una sola flecha por tarjeta de estado, y acción por fila, en escritorio y móvil.
3. En el modal del convenio, comprobar notas, origen, programas y botón Crear convocatoria.
4. En Gestión de convocatorias, abrir la creación desde un aliado que tenga al menos dos programas autorizados y habilitados; verificar dos bloques con archivos de imagen independientes.
5. Guardar dos programas y comprobar **dos IDs de convocatoria distintos** y dos imágenes distintas en Gestión, pero la misma URL y QR de Registro.
6. Probar el fallo de la segunda imagen o validación: no debe quedar ningún registro de convocatoria parcial.
7. Validar Vinculación, Inscripción y Telefonía sin cambios de comportamiento.

## Precauciones

No ejecutar un publicador global sobre la instalación personalizada de Hostinger. No hacer `ALTER TABLE convocatorias.id`. No cargar un archivo antiguo de `crear_modal.php` o `convocatoria_crear_compartido.js`; no inventar perfiles o contactos, ni autorizar WhatsApp de manera automática.
