# Reparación de carga y menús de filtros de Correos (v2)

[PR #127](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/127), rama `codex/correos-filtros-convocatorias`. Corrección/publicador: **4927401116d04f907ce5a1f4b4e29d107bce26d5**. Publicador `filtros-correo-v2-eb35c0459e99d9d5`.

## Causa comprobada

1. `selectores_uniformes.js` construye el menú visible una sola vez. El JS de convocatorias reemplazaba después las opciones del select nativo, sin actualizar ese menú. Reproducido en Chrome con el componente institucional real: tres opciones nativas y una sola visible. La prueba anterior no incluía ese componente global.
2. El filtro Convenio se derivaba exclusivamente de las relaciones de las convocatorias. El endpoint real de Hostinger devuelve `convenios: []` en las siete convocatorias; por eso no ofrecía un convenio que sí existe en Aliados.

Datos reales comprobados el 22/10/2026 mediante la respuesta JSON autenticada y el comando SQL de sólo lectura ejecutados por la usuaria en Hostinger:

| Dato | Cantidad |
| --- | ---: |
| Estados del endpoint | 32 |
| Convenios activos en la fuente de Aliados | 1 |
| Convocatorias activas del endpoint | 7 |
| Convocatorias sin convenio relacionado | 7 |
| Convocatorias sin estado relacionado | 3 |

Las tres convocatorias sin estado corresponden a registros distintos llamados Handly. No se corrigen esos datos ni se inventan relaciones. El único convenio aparecerá; si se selecciona y no hay convocatorias relacionadas, la lista de convocatorias debe quedar vacía. Sin filtros se conservan las siete; por estado se aplica únicamente la cobertura realmente registrada.

## Cambios limitados

- `CorreoConvocatoriaConsultaModel.php`: añade catálogo independiente de convenios con la misma fuente/reglas de `CrearConvocatoriaModel::listarConveniosAliados`: `aliados_asignaciones` + `seguimientos_vinculacion`, ambos activos, catálogo `estados` y referencia de `seguimientos_vinculacion_post_envio`. No invoca sus métodos de creación de tablas. Deduplica por ID; mantiene `convocatoria_estados` y `marketing_convenio_convocatorias` como relaciones reales. Un fallo de la consulta de convenios avisa sin vaciar los otros catálogos.
- `correo_convocatorias.js`: sincroniza exclusivamente los tres menús visibles con sus selects originales después de cargar/filtrar/limpiar y restaurar su rótulo. Conserva las clases institucionales y el componente global intacto. Convenios inicialmente independientes de convocatorias; al elegir estado se consideran su estado registrado y la cobertura de sus convocatorias vinculadas. El filtro de convocatorias combina ambas condiciones y no cambia el convenio por falta de coincidencias.
- Diagnóstico nuevo `tools/diagnosticar_filtros_correo.php`: sólo CLI y SELECT, cantidades y hashes de código; sin contactos, credenciales, envío ni recuperación.

En esta corrección permanecen **idénticos a v1** el controlador, vista, `correos_marketing.js` y compositor CID, incluidos sus flujos de envío, borradores e historial. También están intactos los servicios Hostinger, SMTP, tokens, firma, recuperación y estilos. No hay migraciones ni cambios de datos.

## Validación y límites

Chrome con `selectores_uniformes.js` real: reproducción del fallo v1 y reparación de los menús visibles; convenios sin convocatoria, filtros combinados, limpieza de rótulos, conservación de destinatario/asunto/texto/archivo manual, imagen, escritorio/móvil y selector ajeno intacto. API y envío interceptados, sin mensajes reales. Regresiones PHP/JS dirigidas y comparación de 24 archivos protegidos con main y de cuatro componentes con v1: correctas. Publicador probado en sitio ficticio con Hostinger v4 y filtros v1: check sin escritura, apply/restore exactos, repetición idempotente y rechazo de incompatibles/ediciones posteriores.

Consultas reales locales: 32 estados, 2 convenios activos y 0 convocatorias activas. Son una base distinta de Hostinger. Los totales de producción de la tabla anterior proceden de las comprobaciones ejecutadas allí, no de estos datos ni de fixtures.

Pendiente: aplicar con autorización y verificar los menús reales, imagen y diagnóstico desde Hostinger después de la actualización. La sesión disponible para diagnóstico de navegador redirigió a login; no se modificó la redacción abierta ni se autenticó otra cuenta. No se declara validada la interfaz de producción sólo por las pruebas locales. Sin CI global, fusión ni despliegue automáticos.

## Publicación de la reparación

Para instalaciones que ya tienen los filtros v1. No volver a ejecutar el instalador v1 para reparar este problema. El siguiente publicador modifica sólo modelo/JS de filtros y añade el diagnóstico CLI; conserva archivos personalizados o se detiene si no reconoce su versión.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/4927401116d04f907ce5a1f4b4e29d107bce26d5/tools/publicar_filtros_correo_hostinger.sh" \
  -o "$HOME/impe-publicadores/filtros-correo-49274011.sh" &&
printf '%s  %s\n' \
  '4fcfcc893c23db5b44dcc1a3aadbb79f5f3f2bfa0ce2fb3ad6591dccf8f63cdb' \
  "$HOME/impe-publicadores/filtros-correo-49274011.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/filtros-correo-49274011.sh" --check
```

Sólo después de hash/revisión compatibles, al decidir publicar:

```bash
bash "$HOME/impe-publicadores/filtros-correo-49274011.sh" --apply
php "$IMPE_CORREOS_PUBLIC/tools/diagnosticar_filtros_correo.php"
```

Guardar el comando **Rollback** exacto impreso por apply: usa el mismo script con `--restore` y la ruta real del respaldo. No forzar versiones personalizadas. Recargar el CRM con Ctrl+F5 y abrir Redactar correo; verificar las opciones del menú visible, no sólo el HTML nativo. Comparar el diagnóstico con los registros actuales: los datos pueden cambiar legítimamente desde las comprobaciones anteriores. No se modifica configuración privada ni se ejecutan consultas de escritura o envíos con el publicador.
