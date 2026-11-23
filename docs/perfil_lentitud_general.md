# Medición temporal de la lentitud general

La mejora anterior fue parcial. No hay evidencia suficiente para atribuir toda la demora a las consultas del catálogo, a Territorios ni al navegador. La lentitud afecta acceso, recarga, cambios de módulo y consultas auxiliares. La lectura de producción descarta código de admisión MySQL y referencias a esa cola en .htaccess/.user.ini; no se aplican cambios a ese mecanismo.

Este paquete añade medición por fases al punto de entrada común `index.php`, conservando la autorización y los módulos instalados:

- `sesion`: espera y apertura de la sesión PHP.
- `config`: carga de configuración y utilidades iniciales.
- `autorizacion`: comprobaciones comunes de identidad/permisos y rutas hasta seleccionar el controlador.
- `controlador`: carga e instancia del controlador.
- `despacho`: comprobación del método solicitado.
- `accion`: ejecución del módulo y su salida hasta terminar la petición.

Las fases disponibles antes de la salida se agregan como cabeceras Server-Timing. La duración total y la fase de acción se guardan en un archivo privado. Si una petición termina anticipadamente, su tramo final aparece como `salida_anticipada`. Los contadores mysqlnd informan envíos de comandos, conexiones y reutilizaciones durante la petición; no contienen SQL ni acreditan por sí solos la duración de consultas individuales.

La medida empieza cuando PHP ya está ejecutando index.php. La cola previa de workers/servidor/CDN y el tiempo de red no están incluidos. Si el perfil PHP es rápido y Network sigue mostrando muchos segundos, comparar la pestaña Timing/Waiting con estos valores para identificar la espera exterior a la aplicación. No se declara resuelta la lentitud al instalar la medición.

## Privacidad y duración

Se registra únicamente ruta controlador.acción, GET/POST, código HTTP, hora UTC, tiempos por fase y contadores. No se guardan identificadores de usuarios, IDs de expedientes, cookies, cuerpos POST, contraseñas, tokens ni el contenido de consultas. La ruta acepta sólo identificadores alfanuméricos controlados.

La activación dura una hora desde la publicación; después la medición se omite automáticamente. El archivo es `impe-private/perfil-peticiones.jsonl`, con permisos 0600 y límite aproximado de 1 MB. Se reinicia bajo candado al superar ese tamaño. La escritura usa un candado no bloqueante: si otro proceso lo está usando se omite la muestra. Sin activación válida no se generan registros ni cabeceras adicionales. No se modifica la sesión, las reglas de permisos, las consultas ni la configuración de MySQL.

## Publicación y lectura

Descargar del mismo commit `tools/publicar_perfil_peticiones.py` y `tools/perfil_peticiones_release.json`. Ejecutar con `--site` apuntando a public_html y `--backup-root` al directorio hermano impe-private. Primero `--check` y después `--apply`. Se verifican todos los bloques y la sintaxis antes de escribir y se crea un respaldo privado. La comprobación no activa ni escribe nada. Una repetición vuelve a activar una hora sin duplicar el código.

Abrir varios módulos, recargar uno y reproducir la espera de algún selector. Después ejecutar:

```bash
php "$HOME/domains/fundacionrededumex.org/public_html/app/helpers/PerfilPeticion.php"
```

La lectura sólo se ejecuta desde PHP CLI; no se publica un endpoint web con el informe. Resume las peticiones de la última hora, ordenadas por mayor demora. Muestra la mediana y el máximo de cada ruta y las fases de su petición más lenta. Conservar esta salida junto con el tiempo de Network.

Para restaurar, ejecutar el publicador con `--restore RUTA_RESPALDO` usando el mismo site/backup-root. Restaura el índice original, retira el helper añadido y desactiva la bandera; protege modificaciones posteriores. El registro privado permanece disponible para comparar.

## Validación

Pruebas de fases con pausas conocidas y bloqueo real entre dos procesos que comparten una sesión PHP; atribución de esa espera a sesión y no a acción. Respuesta normal conservada, entradas sensibles ausentes del registro, informe CLI, expiración, bandera corrupta y límite de archivo. Publicador probado con el índice anterior y la variante con autorización/conexión compartida, LF/CRLF, Python 3.6, comprobación sin escritura, aplicación, repetición, restauración y rechazo de un índice incompatible antes de escribir. No se realizaron llamadas ni envíos de mensajes/correos.
