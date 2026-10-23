# Indicadores Clave de Marketing

Se sustituyen las cuatro tarjetas del inicio por un único panel con cinco indicadores, separadores, iconos, barras y botón de detalles según la referencia. Manrope y paleta existentes; horizontal en escritorio, filas en tableta y vertical en móvil. No se modifica ningún otro widget ni se agrega una pantalla.

| Indicador | Fuente y definición |
|---|---|
| Publicadas | `ConvocatoriaModel::obtenerResumenDashboard()`: COUNT de convocatorias registradas, sin filtro de mes ni metas. Respeta la definición actual; no reconstruye registros eliminados por el flujo existente. |
| Convenios | Fuente de Convenios de Marketing: seguimiento activo con asignación activa en `aliados_asignaciones`, incluidos históricos/migrados, más `aliados_manuales` activos. Cada origen/ID una vez. |
| Aliados | Seguimientos activos formalizados según `AliadoModel`, más manuales activos. Se distingue esta población de la de Convenios. |
| Con convocatoria activa | Mismo conjunto de aliados, con al menos una convocatoria `estado=1` relacionada por las tres tablas existentes de vinculación/origen/manual. UNION y clave origen/ID evitan duplicados; no se vincula por coincidencia de nombre o estado. |
| Cobertura | Método existente de cobertura: estados distintos con convocatoria activa / catálogo de entidades activas (32 en el CRM). No cuenta municipios. |
| Próximas a vencer | Lista existente, agrupada por ID, con estado activo y término entre hoy y hoy+7, inclusive. Excluye vencidas y día 8. |

Las barras significan, respectivamente: estados con convenios / catálogo; aliados con convocatoria activa / aliados; estados cubiertos / catálogo; próximas a vencer / convocatorias activas. Porcentajes redondeados a un decimal. Publicadas no tiene barra. Sin tablas de relación se indica «Relación no disponible»; ante error se muestra «—», no un cero inventado.

«Ver más detalles» abre la Gestión de Convocatorias existente, con su permiso actual; no se presenta como un informe común de Convenios/Aliados.

Archivos de producción: `HomeController.php` agrega sólo la lectura dentro de la rama Marketing; modelo nuevo `MarketingIndicadoresModel.php`; bloque nuevo `marketing_indicadores.php`; sustitución localizada en `dashboard/marketing.php`; CSS específico en `convocatorias.css`; diagnóstico CLI. Sin cambios en creación/edición, modelos originales, correo/API, recuperación, roles, configuración ni migraciones. La sincronización previa del dashboard se conserva; la consulta nueva y el diagnóstico sólo leen.

Pruebas dirigidas pasadas: consultas reales contra SQLite en memoria (adaptación de funciones de fecha MySQL), fuentes con cantidades distintas, duplicados, manual/institucional con mismo ID, fechas límite/vencida aún marcada activa, actualización, vacíos y esquema opcional. Chrome a 2172/1440/1280/820/390 px: un panel, cinco indicadores, sin recortes del panel ni desbordamiento añadido; widgets comparados antes/después. A 1280 px se detectó un desbordamiento preexistente en el listado de cobertura pendiente, idéntico a la base y fuera de este cambio. Publicador probado sobre bases main/v4, LF/CRLF, comentarios PHP, respaldo/restauración exacta e idempotencia; rechaza personalizaciones incompatibles. Sin producción ni CI global. Capturas ficticias: `tmp/marketing_indicadores/`.

## Publicación por SSH

Rama `codex/marketing-indicadores-clave`, commit del panel `ddf5b732b1a0aa911516ad3b864a4f18126bc851`. Base aprobada 66ecd65a, descendiente de origin/main, con los avances aún no fusionados de #128/#130/#131. El PR a main incluye esa base; el cambio específico del panel es ddf5b732. Sin fusión ni despliegue automático.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/ddf5b732b1a0aa911516ad3b864a4f18126bc851/tools/publicar_indicadores_marketing_hostinger.sh" \
  -o "$HOME/impe-publicadores/indicadores-marketing-ddf5b732.sh" &&
printf '%s  %s\n' \
  'da31b127fc79a3e230516539bf4761b56c61d9c052efbefc954e3a098c74fad2' \
  "$HOME/impe-publicadores/indicadores-marketing-ddf5b732.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/indicadores-marketing-ddf5b732.sh" --check
```

Sólo con hash válido y revisión compatible:

```bash
bash "$HOME/impe-publicadores/indicadores-marketing-ddf5b732.sh" --apply
php "$IMPE_CORREOS_PUBLIC/tools/diagnosticar_indicadores_marketing.php" --check
```

Se publican sólo seis archivos, respaldados fuera de public_html. Guardar el comando `--restore` con la ruta exacta que imprime. Si detecta versiones incompatibles, detenerse: no forzar ni sobrescribir archivos personalizados. Después recargar el dashboard con Ctrl+F5. Las cifras y la presentación aplicadas en Hostinger quedan pendientes de ese diagnóstico/revisión; las capturas entregadas usan datos ficticios.
