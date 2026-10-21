# Comprobar PHP web para oficios en Hostinger

Fecha: 20/10/2026. Diagnóstico temporal, sin cambio de conversor.
Se conserva el [diagnóstico anterior](OFICIOS_PDF_BLOQUEO_HOSTINGER.md), commit
`9eb43322`. La modificación de `disableFunctions` en hPanel necesita una nueva
medición desde PHP web; el resultado anterior de SSH no responde esa pregunta.

## Estado de la evidencia

| Entorno | Evidencia real |
| --- | --- |
| Hostinger, PHP CLI, antes del cambio comunicado | PHP 8.2.34; `proc_open` bloqueado; sin LibreOffice/soffice en PATH de SSH. |
| Hostinger, PHP web, después del cambio de hPanel | **Pendiente**. No se dispone de una sesión administrativa en producción para ejecutarlo directamente. |
| Local, PHP web de pruebas | PHP 8.2.12, SAPI `cli-server`; comprobado con `proc_open` habilitado y deshabilitado deliberadamente en servidores de prueba distintos. |
| Local, proceso fijo sin documentos | Ejecución satisfactoria. Control de espera comprobado con un proceso lento de ensayo. No demuestra conversión en Hostinger. |
| PDF y formato REDMEX | Se conservan las pruebas anteriores. No se repitieron conversiones ni se generaron documentos en este diagnóstico. |

El estado del motor y su compatibilidad **en Hostinger** se conocerán al obtener
la tabla web. Aunque `proc_open` funcione, hace falta un conversor utilizable y
una prueba posterior del PDF y de su formato. La presencia de PHPWord/Dompdf
no certifica la fidelidad: el respaldo falló en la comparación anterior.

## Preparar la herramienta por SSH

Estos pasos son manuales; no se han ejecutado contra producción. Sólo crean
el diagnóstico temporal, no despliegan cambios del CRM. No requieren hacer
`git pull`, cambiar de rama, tocar configuración PHP ni ejecutar SQL.

Desde SSH, copiar el bloque completo:

```bash
umask 077
export IMPE_DIAG_WORK="$(mktemp -d "$HOME/impe-diag-oficios.XXXXXX")"
curl --fail --location --proto '=https' --tlsv1.2 \
  'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/fix/ventas-etapa2-audio-operacion/tools/oficios_diagnostico_web.php' \
  --output "$IMPE_DIAG_WORK/oficios_diagnostico_web.php"
```

Comprobar la huella antes de ejecutar. Si no muestra `OK`, detenerse:

```bash
printf '%s  %s\n' \
  'd5d714b5c7161b48dafc21bfd05a05c1a3f543527a4e2d195372e4475fe92382' \
  "$IMPE_DIAG_WORK/oficios_diagnostico_web.php" | sha256sum --check
```

Activar por 15 minutos en la raíz del CRM utilizada en las entregas anteriores:

```bash
php "$IMPE_DIAG_WORK/oficios_diagnostico_web.php" --activar \
  "$HOME/domains/fundacionrededumex.org/public_html" "$IMPE_DIAG_WORK"
```

Si esa carpeta no contiene `app/services/OficioDocxPdfService.php` y `public/`,
la herramienta se detiene. No usar otra carpeta al azar. El directorio privado
debe existir fuera del CRM y de sus antecesores; `mktemp` lo crea bajo HOME con
permisos privados. Sólo se establecen permisos de los archivos recién creados;
no se cambian permisos de archivos o carpetas existentes.

La consola imprime tres datos:

1. Una ruta aleatoria `/public/oficios_diag_…php` para abrir en el navegador.
2. Un token que se pega únicamente en el formulario; no en la URL ni en el chat.
3. La orden exacta `--retirar`, que debe conservarse para la limpieza final.

El token no se guarda en texto claro. Su hash y caducidad quedan en un control
privado fuera del sitio. La herramienta descargada permanece en esa carpeta
privada y no habilita nada por sí sola al accederse por HTTP.

## Ejecutar desde el PHP web real

1. Entrar al CRM **como Administrador**, con la sesión vigente, en su dominio
   HTTPS habitual. No abrir una sesión en otro dominio ni usar una ventana
   privada diferente. Se usa la misma sesión PHP que la aplicación.
2. En ese mismo navegador, abrir el dominio del CRM seguido de la ruta
   `/public/oficios_diag_…php` que imprimió SSH. Usar **HTTPS**.
3. Pegar el token en el campo del formulario.
4. Para comprobar si realmente se permite iniciar procesos, marcar
   **Permitir prueba fija de ejecución**. Antes de ejecutarla se harán todas
   las comprobaciones sin procesos. Sin esa casilla el informe sólo inspecciona
   funciones, archivos y dependencias.
5. Pulsar **Comprobar y deshabilitar diagnóstico** y copiar sólo la tabla de
   resultados al chat. No compartir el token ni el control privado.

No se publican rutas, configuración completa, credenciales, variables de
entorno ni salida de ejecutables. Sólo se informa si la ruta explícita que
ya usa el proyecto está configurada y es utilizable. No se consulta la cuenta
hPanel: la comparación se hace con el resultado relevante de PHP web.

La operación opcional se elige internamente: `LibreOffice --version` cuando
el servicio lo selecciona; de otro modo un proceso mínimo fijo (`true` en Linux
o PHP CLI en Windows, si se detecta). Usa argumentos separados, sin shell,
sin parámetros externos, sin entrada de documentos y con espera de 3 segundos
seguida de terminación y hasta 0,2 segundos de comprobación de salida. Un proceso
mínimo correcto acredita `proc_open`, **no** que Word/LibreOffice conviertan un PDF.
Si falta una función de control, no se inicia el proceso.

Al fallar o agotarse la espera no se prueban comandos alternativos. Si PHP
bloquea `proc_open`, se informa y no se ejecuta ningún proceso externo.
No se generan documentos, no se consultan seguimientos ni se abre la BD.

## Retirar por completo

Una solicitud POST administrativa con token válido lo consume **antes** de
inspeccionar el servidor. No puede repetirse, incluso si la inspección falla.
Al entregar el informe se intenta borrar el PHP temporal; si el servidor no
permite borrarlo, permanece deshabilitado. Un token caducado también impide
el acceso aunque nadie haya utilizado el formulario.

Ejecutar por SSH la orden **exacta `--retirar` impresa al activar**. Sirve antes
o después de usar el formulario: deshabilita el control, elimina el endpoint
si conserva su huella original y elimina su control privado. Si detecta una
edición del endpoint, lo deshabilita y se niega a borrar el archivo editado.

Después de que esa orden termine correctamente, en la misma sesión SSH:

```bash
rm -- "$IMPE_DIAG_WORK/oficios_diagnostico_web.php"
rmdir -- "$IMPE_DIAG_WORK"
unset IMPE_DIAG_WORK
```

No hay eliminación recursiva. Si `rmdir` encuentra algo más, no lo borra.
La URL temporal dejará de existir. Si la sesión SSH se cerró, recuperar la
orden exacta guardada; no inventar un identificador ni borrar carpetas amplias.

Si aparece 403, verificar que se usa HTTPS y una sesión vigente de Administrador.
Si aparece 404 antes del informe, puede estar deshabilitado, caducado o ser
ilegible su control privado; no cambiar permisos generales para sortearlo.
Retirar la activación y preparar otra cuando se haya identificado la causa.

## Archivos y pruebas

- `tools/oficios_diagnostico_web.php`: administrador CLI y código del diagnóstico
  temporal en un solo archivo; deshabilitado por defecto.
- `tests/oficios_diagnostico_web_test.py`: pruebas locales con el servidor PHP,
  sesiones ficticias y carpetas aisladas; sin dependencias adicionales.
- Este documento y una referencia añadida al diagnóstico anterior.

Prueba dirigida ejecutada satisfactoriamente:

```text
python tests/oficios_diagnostico_web_test.py
OK: HTTP local; administrador, HTTPS, token, caducidad, uso único, retirada,
proc_open bloqueado/habilitado, prueba fija y timeout. Sin BD ni conversión.
```

También se verificó la sintaxis PHP. Las pruebas rechazan parámetros de comandos
arbitrarios, comprueban la ausencia del token/rutas privadas en el HTML y verifican
que no queden activaciones al terminar. La simulación de HTTPS sólo existe en el
router local de prueba; la herramienta no confía en esa cabecera de prueba ni
en cabeceras de proxy para autorizar HTTP sin cifrar. No se ejecutó la CI global.

Se revisaron nuevamente las dependencias directas de los cuatro archivos
funcionales señalados en el pedido, sin modificarlos. La preparación DOCX está
en el servicio actual; la conversión requiere su motor; la vista previa llama
al generador y la descarga entrega un PDF ya registrado. Ninguna de esas rutas
se invoca desde esta herramienta.

El siguiente paso depende de la tabla real: si sigue bloqueado, documentar el
bloqueo; si se habilitó pero no hay motor, confirmar esa limitación; si existe un
motor ejecutable, preparar una prueba controlada de conversión y fidelidad
antes de cambiar el funcionamiento del CRM. La generación en Hostinger continúa
pendiente de esa validación, no se declara restaurada por este diagnóstico.
