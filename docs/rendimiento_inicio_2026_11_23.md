# Rendimiento de acceso y navegación

La captura de Network del 23 de noviembre muestra el POST de acceso con 302 en 4.10 s, el documento de telefonía en 13.82 s, avisos de llamadas en 5.12 s y el panel en 3.18 s. Los estilos y fuentes aparecen en caché y tardan milisegundos. Estas duraciones pueden incluir espera, bloqueo de sesión y ejecución del servidor: la captura no permite atribuir todo el tiempo a una consulta concreta.

Diagnóstico recibido del servidor: configuración/conexión MySQL en 3 ms, consulta simple y catálogo en menos de 1 ms, metadatos en 1 ms, PHP 8.2.36 y OPcache activo. Otra prueba dio render de acceso por CLI en 54 ms y web desde el alojamiento en 0.34 s. Desde una conexión externa se observaron respuestas variables de 4–12 s y una cabecera de Hostinger con `x-hcdn-upstream-rt: 4.344`. Por tanto la demora es intermitente y no se puede declarar resuelta únicamente con la prueba por CLI.

## Correcciones acotadas

- RolModel envía el catálogo en una sola sentencia preparada, en vez de ejecutar una sentencia por permiso. Conserva los catálogos personalizados que el servidor agregue a `$permisos`, las asignaciones iniciales, las políticas y las revocaciones. No se cachean los permisos del usuario.
- LoginController crea sus modelos cuando realmente autentica, procesa recuperación o cambia contraseña. Mostrar la pantalla de acceso o recuperación ya no abre conexiones innecesarias. Las validaciones de identidad y contraseña se conservan.
- Las consultas de estado y los sondeos de disponibilidad guardan y cierran la sesión PHP después de verificar permisos y CSRF cuando corresponde. GET y POST de PRESENCIA/POLL no cambian los datos de la sesión; pueden actualizar la base de datos sin retener su bloqueo. Las acciones de modificación, como aceptar o rechazar, mantienen el comportamiento de sesión anterior.
- El servicio de disponibilidad instalado ya no crea tablas en su constructor. El paquete v2 conserva ese servicio sin modificarlo; la protección contra DDL repetido del candidato inicial no se publica sobre esta versión.

## Pruebas

Base MariaDB desechable: comparación del catálogo y relaciones originales y nuevos, tanto en instalación inicial como después de revocar permisos y reinicializar. La lectura de autorizaciones sigue reflejando los cambios vigentes. En la base de prueba, las ejecuciones preparadas de la inicialización completa pasaron de 214 a 131: 83 menos, al agrupar un catálogo de 84 entradas. El número de producción puede variar por sus catálogos adicionales. No se extrapola la duración local a los 13 segundos observados en producción.

Disponibilidad: cero CREATE TABLE en una segunda construcción y restauración de la preparación si falta una tabla. Sesiones reales de archivos con dos procesos PHP: una operación simulada de 1.2 s no bloquea otra petición en estado, GET de disponibilidad ni POLL; una acción de modificación conserva el bloqueo. También se prueban acceso denegado, CSRF incorrecto, inicio válido, contraseña incorrecta, recuperación sin enviar correo y creación de modelos bajo demanda sin duplicarlos.

## Publicación y medición posterior

El publicador v2 aplica bloques exactos sobre tres archivos instalados; no reemplaza el RolModel completo ni catálogos personalizados. Requiere Python 3.6 o superior y PHP CLI. Primero ejecuta `--check`, luego `--apply`, guardando el respaldo fuera de public_html. Ante un bloque incompatible se detiene antes de escribir. Admite `--restore RUTA_RESPALDO`, protegiendo cambios posteriores.

Después de aplicar, iniciar una sesión de prueba y medir de nuevo el POST de acceso, telefonía y home en Network; repetir un cambio de módulo con los sondeos activos. Comparar en la misma conexión y un horario similar. Si persiste una demora alta, abrir Timing de la petición lenta para distinguir Queueing/Stalled, conexión, Waiting/TTFB y descarga, y revisar los límites/colas de PHP del alojamiento. Aún hace falta esa medición en producción.

No se enviaron mensajes, correos ni llamadas durante las pruebas; no se modificaron secretos ni datos del servidor de producción.

## Paquete v2 compatible con producción

La primera comprobación se detuvo en LoginController sin escribir archivos. Los fragmentos recibidos del servidor mostraron cambios posteriores: conexión mediante Database::compartida(), recuperación delegada en CorreoRecuperacionService y disponibilidad con sondear(), callback de origen y manejo de fallos temporales. El constructor instalado de TelefoniaDisponibilidadService ya no ejecuta DDL.

El paquete v2 actualiza sólo RolModel, LoginController y TelefoniaController. Conserva la conexión compartida al diferir la creación de modelos; agrega la preparación al comienzo de las acciones sin reemplazar sus cuerpos. En la disponibilidad instalada sólo inserta el cierre de sesión después de validar método/permisos/CSRF y antes de entrar al servicio. No cambia sondear(), su callback, los manejadores de error ni la recuperación de contraseña. TelefoniaDisponibilidadService queda fuera del paquete, pues el servidor ya tiene esa mejora.

Las variantes se reconocen por bloques exactos, sin coincidencias aproximadas. Se prueban la versión anterior y los fragmentos instalados recibidos del usuario, con LF/CRLF, repetición, sintaxis y sesiones concurrentes. La prueba específica confirma que los modelos continúan recibiendo la misma conexión compartida y que la recuperación sigue delegándose al servicio instalado.
