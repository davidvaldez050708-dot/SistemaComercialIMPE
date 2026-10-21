# Aliados Internacionales: interfaz y acceso

Corrección adicional sobre el PR #114. Conserva la asociación aliado–programas, la institución canónica, los convenios, los formularios exclusivos y la procedencia. No cambia tablas, tokens, programas asignados ni los flujos nacionales.

## Interfaz

- Directorio compacto, búsqueda automática sin acentos por nombre/país/región/ciudad, filtro de actividad y paginación de ocho instituciones.
- Indicadores calculados a partir de las mismas consultas del directorio; sin consultas por tarjeta.
- Detalles y formularios generales separados de las convocatorias individuales. Cada convocatoria conserva programa, formulario, vigencia, URL persistida y consulta/descarga de QR.
- Acciones agrupadas en un menú Bootstrap. Tres modales reutilizables con foco y desplazamiento interno; editor agrupado por institución/ubicación, contacto y programas.
- Reutiliza `aliados.css`, `dashboard.css`, `convocatorias.css`, botones `btn-system-*` y el modal compartido de Crear convocatoria. `aliados_internacionales.css` solo añade disposición dentro del módulo y sus modales; no cambia estilos globales.

## Autorización

No se conceden permisos nuevos ni territorios. El mismo predicado rige navegación y endpoints internacionales:

| Permisos efectivos | Consulta internacional |
|---|---|
| Administración con `aliados.ver` | Sí |
| `aliados.ver` + `convocatorias.ver` (Cuenta Clave) | Sí, sin exigir asignaciones nacionales |
| `convocatorias.ver` + `convocatorias.crear` (Marketing) | Sí, incluso sin `aliados.ver` |
| `aliados.ver` + `aliados.internacionales.configurar` | Sí |
| Solo `aliados.ver` o solo `convocatorias.ver` | No |

Alta/edición exige `aliados.internacionales.configurar`; convenio exige además `convenios.gestionar`; crear convocatoria exige `convocatorias.crear`; QR exige `convocatorias.ver`. El Administrador conserva las restricciones operativas del CRM. Las respuestas con datos personales siguen siendo administrativas. Marketing sin acceso nacional no obtiene territorios ni datos nacionales por esta corrección.

## Validación

- PHP: matriz de permisos, validaciones, transacciones y pruebas con MariaDB aislada.
- Formularios generales, exclusivos, programas no autorizados, duplicados, CSRF, convenio canónico y dos convocatorias persistentes.
- Playwright con controladores PHP reales: navegación de Marketing/Cuenta Clave sin territorios, alta/edición/convenio, formulario compartido, enlaces, QR real decodificado, filtros, paginación y estado vacío.
- Directorio y modales en 1920, 1366, 1024, 768, 390 y 360 px; foco, cierre, un solo backdrop, botones dentro de pantalla, sin desbordamiento. Fixtures con todos los programas del catálogo y varias convocatorias; Handly conserva únicamente sus dos programas autorizados.
- Comparativas antes/después y capturas por resolución se generan en `IMPE_TEST_OUTPUT`; Actions las adjunta como artefactos. Los routers y las sesiones de prueba viven fuera del CRM, en un servidor local sobre BD `impe_test_handly*`.

## Publicación

Ver `docs/aliados_convenios_convocatorias_hostinger.md` y su bloque actualizado de despliegue. El publicador selectivo incluye 22 archivos de aplicación PHP/JS/CSS, con variantes exactas de main/PR112 y de la versión anterior de PR114. Los parches PHP solo preservan personalizaciones ajenas a los bloques modificados; variantes incompatibles o JS/CSS desconocidos detienen la revisión sin escribir el sitio. No se debe forzar ni sustituir un archivo en conflicto.

Este rediseño no requiere otra migración. Si aún no se publicó la funcionalidad del PR114, su migración aditiva original sigue siendo un paso manual después de respaldar la BD. El publicador no ejecuta SQL.
