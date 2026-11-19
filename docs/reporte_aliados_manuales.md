# Aliados manuales en el panorama general

El reporte debe incluir aliados manuales activos y formalizados dentro de los territorios autorizados. Antes, las extensiones de Hostinger incorporaban manuales solamente al seleccionar un estado, mientras el directorio territorial ya los sumaba.

El controlador calcula el alcance en servidor y lo usa en panorama HTML, catálogo de municipios y exportación PDF. No acepta un alcance territorial enviado por HTTP. El modelo reutiliza su conexión y consulta los manuales del conjunto autorizado en un lote parametrizado. Si la tabla opcional de manuales no existe, mantiene el comportamiento previo; no crea tablas ni modifica registros. Las claves de actividad distinguen manual:ID de institucional:ID y solo IDs institucionales positivos llegan a sus consultas de eventos. Un registro manual no se presenta como convenio formalizado.

## Perfiles distintos

Este PR adapta la base GitHub con un lector opcional de manuales de solo lectura, incluida la fotografía del último envío si existe su tabla. No incorpora el módulo privado completo de registro de Hostinger, sus decoradores, snapshots, paquetes ni configuraciones. El lector opcional no sustituye los permisos de los endpoints.

Hostinger tiene una conciliación privada de cuatro archivos que conserva sus servicios y decoradores existentes: AliadoReporteController, AliadoModel, AliadoRegistroService y ReporteAliadosPanoramaService. **No instalar los archivos del PR directamente sobre Hostinger.** La conciliación requiere comparar sus cuatro hashes de origen actuales y preparar respaldo antes de autorizar publicación. La restricción MySQL del proveedor sigue siendo condición independiente para no desplegar mientras persista.

## Validación

`python tests/reporte_aliados_general_test.py` ejecuta código real del modelo, lector y panorama con adaptador SQL sobre SQLite en memoria. Requiere PHP 8.1+ con PDO SQLite y mbstring, y Python 3. Se puede definir PHP_BIN para seleccionar el ejecutable.

Escenarios: 14 manuales más un formalizado -> total 15; estado 14; municipio 7; exclusión de inactivos y territorios ajenos; conjunto vacío y estado ajeno sin lectura manual; claves sin colisiones ni actividad institucional atribuida a manuales; corte temporal; cadencia de consultas por lote; cero conexiones adicionales; vista HTML y plantilla PDF con el mismo conjunto; último envío manual por fecha/id; ausencia de la tabla opcional. No hay acceso de red, MySQL de producción ni generación PDF/recursos remotos. Se simulan proveedores de eventos y conexión mediante un adaptador; no equivale a staging Hostinger.

También se validó localmente la conciliación privada contra la copia recibida, reproduciendo original general 1 frente a candidato 15, estado 14 y municipio 7. No se certifican los archivos hoy publicados hasta recibir sus hashes.

El historial de actividad del periodo conserva sus fuentes institucionales: este cambio no añade un motor de métricas de eventos manuales. Sus envíos disponibles contribuyen a la fotografía actual. Sin merge ni publicación automáticos.
