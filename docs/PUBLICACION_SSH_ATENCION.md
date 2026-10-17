# Publicar la atención del analista por SSH

Este publicador instala la entrega de aplicación `322785588f703815de2e95ef65d453425590e20f`.
Publica 22 archivos PHP y JavaScript. Las comprobaciones y los archivos de
preparación se generan fuera del sitio. No hace `git pull` dentro de producción
ni cambia la rama o los archivos de trabajo de `crm-preparacion`.

Rutas predeterminadas:

- Copia Git independiente: `$HOME/crm-publicaciones/atencion-3227855`.
- Sitio: `$HOME/domains/fundacionrededumex.org/public_html`.
- Respaldos: `$HOME/respaldos-crm`.

Descargar `tools/publicar_atencion_analista_hostinger.sh` desde una versión
concreta y revisada de este repositorio a una ruta fuera de `public_html`.

La descarga utiliza HTTPS sin cargar reglas globales o de sistema de Git. Estas
variables sólo afectan al publicador; no se modifica la configuración de la
cuenta. La copia independiente evita heredar un origen SSH o reglas locales de
`crm-preparacion`. También fuerza HTTPS para obtener objetos de un clon parcial.
No hace falta registrar una llave SSH en GitHub para este repositorio público.
Una preparación con reglas locales `insteadOf` se rechaza antes de publicar.

## Revisión

```bash
bash "$HOME/publicar-atencion-analista.sh" --check
```

El modo de revisión obtiene los objetos Git necesarios, compara cada archivo,
prepara los parches y valida sintaxis PHP. Si Node está disponible también valida
sintaxis JavaScript. No publica archivos ni ejecuta PHP de la aplicación. No
consulta ni cambia MySQL. La preparación puede descargar objetos de GitHub y
crear archivos temporales; «sitio intacto» se refiere a la carpeta pública.

Una salida `CONFLICTO` requiere comparar ese archivo antes de publicar. El
publicador no sustituye por fuerza una personalización incompatible. Las
personalizaciones compatibles ajenas al parche se conservan. Si la copia Git es
superficial y faltan revisiones, descarga el historial necesario.

## Publicación

Con una revisión compatible y las extensiones sin llamada activa:

```bash
bash "$HOME/publicar-atencion-analista.sh" --apply
```

El modo de publicación repite la revisión, bloquea otras publicaciones de esta
entrega y verifica que los archivos no hayan cambiado durante la preparación.
Respalda todos los originales afectados antes de escribir. Instala primero los
servicios nuevos y después los consumidores. Cada reemplazo utiliza un archivo
temporal en la misma carpeta y una operación de renombrado.

Si falla la escritura o llega una señal de interrupción normal, restaura los
originales y retira los archivos nuevos. Una terminación forzada del proceso,
corte eléctrico o disco inaccesible puede impedir ejecutar esa restauración;
el respaldo y `archivos.tsv` permiten revisar qué se había instalado. No es una
sustitución atómica de toda la aplicación ni restaura cambios de base de datos.
Una segunda ejecución sobre los archivos ya publicados no genera otro respaldo.

La ruta de respaldo se imprime al finalizar. Los archivos ajenos a la lista de
esta entrega, credenciales, configuración privada y audios quedan fuera del
publicador. La migración SQL aditiva de esta entrega sigue disponible en el
repositorio; las dos tablas se crean al utilizar el flujo si el usuario MySQL de
la aplicación tiene permiso CREATE.

Al terminar, cerrar sesión y volver a entrar para recargar también el shell
persistente del teléfono. Probar una transferencia 100 → 101 y el registro de
datos. Un resultado correcto de la publicación verifica archivos y sintaxis;
la llamada real y sus webhooks se comprueban en el CRM.

## Verificación del publicador

`tests/publicacion_atencion_hostinger_test.py` crea sitios desechables a partir
de la versión anterior y comprueba revisión sin escrituras, publicación exacta,
respaldo, conservación de privados, repetición, personalizaciones, conflictos,
PHP inválido, bloqueo simultáneo y restauración tras un fallo inducido.
El flujo `publicacion-atencion-ssh.yml` ejecuta estas pruebas sin acceso SSH a
Hostinger y sin credenciales de producción. Además clona GitHub realmente por
HTTPS mientras una configuración global de prueba intenta redirigirlo a SSH.
