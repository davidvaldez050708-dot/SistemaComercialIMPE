# Convenios manuales en el filtro de Correos

Corrección `35fde7e9b6a9da2890c53afd1e42d3bd06b09b45`, sobre filtros v2 (`4c8ff42c`). No instala otros módulos, crea tablas ni cambia registros.

## Causa y cambio

El filtro sólo consultaba `aliados_asignaciones` → `seguimientos_vinculacion`. Convenios en `feat/aliados-programas-registro` suma `AliadoRegistroService::manuales()`: `aliados_manuales.activo=1` unido a `estados` por `estado_id`. Se usan las mismas fuentes y criterios SELECT, sin importar sus operaciones de escritura ni cambiar ese módulo.

Relaciones reales: `marketing_convenio_convocatorias`, `marketing_aliado_manual_convocatorias` y `marketing_aliado_convocatorias_origen`; se detectan las tablas disponibles sin migraciones. Vinculación conserva su ID numérico; el manual usa `manual:ID` y muestra su ID real. El prefijo distingue tablas con IDs coincidentes, sin inventar identificadores. Se deduplica por origen/ID, nunca por nombre. Convocatoria conserva ID y cobertura en `convocatoria_estados`.

Archivos funcionales: `CorreoConvocatoriaConsultaModel.php` (SELECT y clave), una línea de `CorreoConvocatoriaComposicion.php` (aceptar la clave en el filtro) y tres líneas de `correo_convocatorias.js` (identidad, etiqueta y estado estricto). Imágenes, transporte, persistencia, borradores, adjuntos, historial, recuperación, controlador, vista y CSS permanecen intactos.

## Evidencia

Hostinger, consultas SELECT ejecutadas por el usuario el 22/10/2026: manual activo **ID 1**, estado **11**; ambas tablas de relaciones devuelven convocatorias **8, 9, 10 y 11**. El nombre PRUEBA y el institucional #155 fueron reportados por el usuario. Falta verificar el catálogo actualizado después de publicar.

Pasaron pruebas PHP/JS con SQL/API simulados: ambos orígenes, mismo nombre/ID sin colisión, deduplicación, tablas opcionales, estado, sin convocatoria, claves manipuladas y metadatos con aislamiento de propietario. Chrome real con vista/selectores originales: selección manual e imagen, limpieza, contenido/archivo intactos, escritorio/móvil y envío interceptado. Comparación: 24 archivos protegidos idénticos a main, controlador/vista/JS de envío intactos, compositor idéntico salvo validación de clave. Sin envíos ni cambios de contraseña.

Publicador probado en sitio ficticio: check sin escritura, apply/restore exactos, CRLF/comentarios, idempotencia y rechazo de personalizaciones incompatibles. **Pendiente:** aplicar en Hostinger y verificar JSON/menús reales. No declarar aceptación productiva todavía. Sólo aparecen convocatorias activas con cobertura del estado, sin deducir relaciones de la etiqueta «Con convocatoria».

## Publicación selectiva

Único publicador de esta corrección: tres parches anteriores y un diagnóstico CLI. Requiere filtros v2; otra versión se rechaza sin sobrescribir. No usar publicadores anteriores para reparar manuales.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/35fde7e9b6a9da2890c53afd1e42d3bd06b09b45/tools/publicar_convenios_manuales_correo_hostinger.sh" \
  -o "$HOME/impe-publicadores/convenios-manuales-35fde7e9.sh" &&
printf '%s  %s\n' \
  '5b016a4c5a816a309b799a192ee35001b580e860185bf07cb7ca44d7f6bce01a' \
  "$HOME/impe-publicadores/convenios-manuales-35fde7e9.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/convenios-manuales-35fde7e9.sh" --check
```

Sólo si checksum y check terminan correctamente:

```bash
bash "$HOME/impe-publicadores/convenios-manuales-35fde7e9.sh" --apply
php "$IMPE_CORREOS_PUBLIC/tools/diagnosticar_convenios_manuales_correo.php" --estado=11
```

Guardar el respaldo y comando `--restore` exactos que imprime apply. Restore rechaza archivos editados después de publicar.

El diagnóstico muestra IDs/nombres institucionales y convocatorias activas del estado, manuales omitidos y duplicados. Esperar `manual:1`, #155 si sigue activo, `ids_manuales_omitidos: []`, `duplicados_catalogo: 0`, sin avisos. Los IDs 8–11 sólo aparecerán si siguen activos y cubren Guanajuato. Repetir `--estado` con otros estados reales. No muestra contactos/secretos ni envía mensajes.

Ctrl+F5: Guanajuato → ambos orígenes; PRUEBA → sólo sus convocatorias; verificar su imagen; limpiar → conservar texto/adjuntos. El endpoint autenticado `index.php?controller=correoMarketing&action=convocatorias` debe incluir `manual:1` en `convenios` y en las convocatorias vinculadas. Sin despliegue ni merge automáticos.
