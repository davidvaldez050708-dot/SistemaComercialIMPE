# Separación de supervisión de personal

Rama aislada: `feat/supervision-personal-independiente`.
Base Git: `a90b6ad`; referencia funcional: archivos personalizados leídos de Hostinger el 27 de noviembre de 2026.
La referencia de los 11 archivos existentes quedó en el commit `0d5387d`, separada de la implementación para revisar únicamente el cambio funcional posterior.

La preparación inicial se realizó sin cambios en producción. Posteriormente el titular autorizó y ejecutó la publicación de `de71579` mediante SSH: 21 archivos instalados y tres tablas preparadas; la política nueva continúa desactivada. Las lecturas MCP de esta tarea sólo consultan código/metadatos.

## Hallazgos de dependencias

* Supervisión de llamadas: `SupervisionLlamadasRepository::personas` resuelve analistas y asesores mediante la relación explícita `cuenta_clave_asignacion_id`; detalle y audio vuelven a consultar ese alcance.
* Actividad y desempeño de asesores: `VentasActividadRepository::personas` usa la misma relación operativa.
* Desempeño de analistas: `DesempenoService` obtiene personas de `SeguimientoVinculacionModel::obtenerAnalistasSupervisadosCuentaClave` y estados de la Cuenta Clave.
* Reportes de actividad de analistas: el controlador carga expedientes por estados; `SeguimientoTerritorialScope::supervisor` autoriza por estado cuando existe una relación vigente con al menos un analista. Esta regla es más amplia que la pertenencia explícita del autor al equipo.
* Los reportes de cartera/institución y los módulos de convocatorias, información territorial y seguimiento operativo deben conservar sus autorizaciones territoriales.
* `SeguimientoTerritorialScope`, `TerritorioModel` y las tablas de asignación operativa no se modifican. Tampoco se modifican señalización, transferencias, PBX, webhooks, cierres ni guardado de resultados telefónicos.
* Los PDF de actividad tienen caché: se deshabilitó su reutilización para KAM en el nuevo modo de actividad, para impedir acceso posterior a una revocación. Los otros tipos de reporte conservan su comportamiento.
* El endpoint anterior de grabaciones de interacciones autorizaba por expediente/territorio. Ahora valida también al autor exacto para KAM al activar la nueva supervisión, antes de solicitar el audio al proveedor.

## Migración prevista

La preparación importa relaciones explícitas válidas, deduplicadas, con la nueva autorización desactivada. La auditoría identifica relaciones huérfanas, roles incompatibles, exposición histórica por estado y diferencias de alcance; no las corrige ni las elimina. La activación requiere revisar el plan y resolver explícitamente las ambigüedades. La reversión desactiva el nuevo alcance y conserva las asignaciones nuevas y su bitácora.

El titular ejecutó la auditoría de datos reales: 18 relaciones explícitas y 5 accesos heredados por estado, sin duplicados. Autorizó conservar los cinco accesos adicionales: el plan revisado contempla 23 relaciones. La activación sigue pendiente.

## Implementación preparada

- Módulo administrativo con permisos separados `personal.supervision.ver`, `personal.supervision.gestionar` y `personal.supervision.bitacora`; concedidos inicialmente sólo al administrador y delegables explícitamente desde Roles y permisos; tarjetas, fotografías/avatares institucionales, búsqueda, filtros, selección múltiple persistente, resumen y confirmación.
- `SupervisionPersonalAcceso` centraliza alcance por personas para actividad de analistas/asesores, supervisión de llamadas, grabaciones y desempeño. Valida los permisos generales vigentes en el servidor. No guarda autorizaciones de personas en sesión ni en caché entre peticiones.
- Pareja única KAM/colaborador, consultas `EXISTS` y listas únicas; un segundo supervisor no duplica interacciones ni indicadores nacionales.
- Guardado transaccional con revisión global, confirmación HMAC ligada a selección/sesión, CSRF e idempotencia. Las selecciones no se pierden al buscar o cambiar filtros. No hay reintentos automáticos de escrituras.
- Bitácora de importación, ampliaciones revisadas, altas/bajas administrativas, activación y reversión; responsable, fecha UTC y nombres conservados como evidencia histórica.
- Tres tablas nuevas y tres permisos administrativos. Sin modificaciones de las tablas territoriales ni de sus relaciones con KAM. La nueva autorización permanece apagada hasta una activación CLI expresamente aprobada.
- Publicador de 21 archivos por bloques contra la copia personalizada de Hostinger. Comprueba y respalda antes de escribir; no ejecuta SQL ni activa el módulo. No se debe desplegar el diff completo contra `origin/main`: ese origen está atrasado respecto de producción.

## Verificación realizada

Pruebas con MariaDB local efímero, datos ficticios y Chrome automatizado; ninguna llamada, mensaje, descarga de grabación o consulta SQL de producción:

- Auditoría de enlaces huérfanos y exposición por estado; bloqueo de planes/decisiones alterados o no revisados.
- Preparación apagada e idempotente; activación revisada; rechazo de perfiles no elegibles y usuarios inactivos nuevos; conservación de históricos.
- José supervisa a Mayra de CDMX a la vez que Sarahi; retiro de José sin afectar a Sarahi ni territorios. Bloqueo de confirmaciones antiguas y escrituras concurrentes.
- Clases reales de repositorios de llamadas/actividad y fuente de reportes: rechazo de IDs/actores ajenos, coautores excluidos de un expediente compartido, alcance propio/nacional conservado, desempeño entre estados y revocación en la siguiente petición.
- Política del enlace antiguo de audio: autor autorizado, autor ajeno del mismo expediente y autor de otro equipo; permiso general de audio independiente.
- Interfaz a 1440 y 390 px: filtros sin perder selección, resumen sin guardar, confirmación única, reapertura persistida, bitácora, error HTTP/CSRF/método y cierre automático del toast.
- Publicación/restauración únicamente en carpetas temporales: preserva personalizaciones fuera del bloque, LF/CRLF, idempotencia, bloqueo previo sin escrituras ante diferencias y protección de cambios posteriores.

La instalación inicial está en producción y el titular comprobó que carga el módulo. Por instrucción del titular se omitió usar una base staging; las pruebas automatizadas se realizaron en una base local aislada. Quedan pendientes la activación y la comprobación funcional de reportes, PDF y audio con los permisos reales. La nueva reestructuración universal de telefonía debe compararse al preparar el despliegue: cualquier diferencia en un bloque protegido detiene el publicador.

Procedimiento: [MIGRACION.md](MIGRACION.md). Matriz y ejecución de pruebas: [PRUEBAS.md](PRUEBAS.md).
Comandos concretos para la primera fase en Hostinger, sin modificar el CRM: [SSH_HOSTINGER.md](SSH_HOSTINGER.md). Incluye auditoría READ ONLY, detección informativa de duplicados, comprobación de columnas/motores/índices, CHECK y ensayo privado sin credenciales.

Vistas de prueba con datos ficticios: [selección en móvil](vista-movil.png) y [confirmación en escritorio](confirmacion-escritorio.png).

## Ajustes posteriores a la instalación

El paquete `supervision_personal_ajustes_release.json` actualiza únicamente 11 archivos sobre `de71579`: buscador y aviso institucionales, bitácora con etiquetas de cambios y paginación, y permisos delegables por rol. No vuelve a ejecutar la migración ni cambia la activación. Se auditó el catálogo real de Roles y permisos: enumera permisos activos desde MySQL y utiliza `SupervisionRolePolicy` tanto al presentar como al guardar; el mapa de dependencias ahora enlaza Gestión/Bitácora con Ver. Las operaciones CLI de migración siguen siendo exclusivas del administrador.

Procedimiento específico: [AJUSTES_INSTALADOS.md](AJUSTES_INSTALADOS.md).
