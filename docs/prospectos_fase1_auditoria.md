# Auditoría de Prospectos — Fase 1

Base: `544697a162ddecd009fbc2669c4bbd8ab27f883c`. Rama independiente: `feat/prospectos-estados-fase1`. Alcance: portada territorial y listado de consulta. No se continúa a la Fase 2.

## Producción contrastada

El usuario proporcionó los hashes de Hostinger y el diff completo de la vista:

| Archivo | SHA256 confirmado de Hostinger |
| --- | --- |
| `app/controllers/ProspectoController.php` | `23e2399fc6167cb86caf9e8a1c2ddb852862b106d16a7ea4075fa1a36a610c8b` |
| `app/models/ProspectoModel.php` | `5a36abdc654f20935062b5a329d64bb0f902578c71c8c20a14d79177afd965b1` |
| `app/views/prospectos/index.php` | `21abc87d96905e04b247713c782d86e2c5d61029e6b37b5b526765185f48c0d8` |

Controlador/modelo coinciden con Git. La vista tiene reglas Manrope V2 y el bloque `IMPE_PROSPECTOS_TIPOGRAFIA_V3_INICIO/FIN`, que prevalece: 12px, interlineado 1.45, encabezados 800, celdas 600 y nombre 800. El publicador reconstruye esta variante, verifica su SHA y conserva sus reglas; los estilos de tabla originales no se sustituyen por la hoja nueva.

## Consulta y campos existentes

Prospectos consulta `formulario_registros`, la misma tabla que recibe el Registro público general y exclusivo de aliados. No consulta `formulario_inscripciones`. `estados`/`municipios` proporcionan los nombres de residencia; `formulario_aliado_procedencias` aporta aliado/programa. No se modifica ese almacenamiento ni el controlador público.

La auditoría encontró **14 columnas**, no 13: las 13 del documento más «Aliado de origen». También existía el filtro por aliado. El usuario confirmó expresamente conservar ambos, sin agregar filtros nuevos de fases posteriores.

Se mantienen fecha de registro, nombre completo, correo, móvil, segundo móvil, recomendado, aliado, perfil, estado, municipio, trabajo, cargo, nacimiento y origen. Búsqueda por nombre/apellido/teléfono y filtro de aliado conservados. Orden por `created_at DESC,id DESC`; 25 registros por página desde SQL.

## Permisos

La autorización actual usa sesión y `prospectos.ver_todos`, administrable desde Roles. No era una exclusión rígida por nombre de rol. Se conserva exactamente este permiso y su guard previo a las consultas; no se conceden permisos ni se cambia el menú lateral. Sin sesión se conserva la redirección al login; sin permiso se devuelve 403 con presentación institucional.

## Territorios y conteos

Se reutilizan `public/img/estados/*.png` y las clases territoriales de `dashboard.css` usadas por Aliados. Los indicadores reutilizan las tarjetas de `aliados.css`; la navegación replica el selector institucional y usa CSS encapsulado de Prospectos. No se copian mapas ni se cambian componentes globales. Se normalizan los acentos sin depender del locale de iconv; los nombres alternativos de México, Michoacán, Coahuila y Veracruz apuntan a sus archivos existentes.

Las tarjetas muestran **todos los estados activos**, aunque no tengan registros. Sus conteos agrupan exclusivamente `formulario_registros.estado_id`, sin utilizar la ubicación del aliado. «Estados con registros» cuenta los estados activos del catálogo que tienen prospectos. El total global incluye también registros históricos sin estado o asociados a un estado ahora inactivo; esos registros siguen visibles en Todos.

Los indicadores de procedencia usan `EXISTS`, por lo que cada prospecto cuenta una vez. El filtro por aliado también usa `EXISTS`; el listado escoge una procedencia determinista por registro (`MIN(id)`) para preservar los campos existentes sin multiplicar filas cuando hay relaciones repetidas. No se borran ni corrigen relaciones históricas. Total y páginas comparten el mismo WHERE.

Si faltan la tabla o las columnas de procedencia, los indicadores de aliados/generales son `null` y se muestran «— / Sin verificar», con mensaje controlado. La consulta general continúa disponible; no se clasifica como general una procedencia desconocida ni se aplica un filtro no verificable. Errores territoriales y de consulta también se presentan con estilos del módulo.

## Navegación y límites

Entrada predeterminada: Por estados. Tarjeta → tabla de residencia directamente en el mismo `ProspectoController::index`. URL con `estado_id`, controles para regresar/cambiar estado y pestaña Todos. La paginación conserva estado/búsqueda/filtro existente. Los enlaces anteriores de búsqueda o paginación sin `vista` conservan el acceso al listado general.

El JavaScript sólo filtra las tarjetas del catálogo y muestra el estado de carga durante navegación. No descarga ni filtra prospectos en memoria del navegador. Sin JavaScript permanecen disponibles los enlaces y formularios GET. La tabla mantiene su desplazamiento horizontal dentro del contenedor.

No se agregan exportaciones, filtros por programa/fecha, detalle individual, relaciones nuevas, migraciones, ni cambios en formularios públicos, enlaces, QR, inscripciones, programas, aliados o convocatorias.
