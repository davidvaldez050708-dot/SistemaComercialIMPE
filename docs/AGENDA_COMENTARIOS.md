# Comentarios en Agenda

Integración aditiva sobre `5116b62c284de2f9e27440f77982964e876c7402`.
No cambia solicitudes, confirmaciones, cambios de fecha, reuniones, seguimiento,
Ecards, territorios ni sus permisos. Las acciones de reunión y su JavaScript
permanecen intactos. Los comentarios usan controlador, servicio, repositorio,
JavaScript, CSS y almacenamiento propios.

## Uso y destinatarios

- El botón **+** de cada día abre **Agregar comentario**. Admite entre 1 y
  250 caracteres Unicode, fecha y hora opcional. **Sin hora** es el valor inicial.
- Al seleccionar un día aparece un panel con las pestañas **Reuniones** y
  **Comentarios**. Cerrar ese panel restablece el panel anterior de pendientes.
- Se muestran nombre del autor, fecha de creación y hora del recordatorio cuando
  exista. El nombre se obtiene del usuario autenticado en el servidor.
- Los comentarios son compartidos entre los usuarios con el permiso existente
  `reuniones.ver`, incluidos los perfiles de consulta y el administrador si
  tienen ese permiso. No se conceden permisos nuevos a ningún rol.
- Sólo el autor puede borrarlos, mediante confirmación. El servidor verifica la
  propiedad; el borrado lógico conserva la trazabilidad y retira los avisos.
- Cada destinatario puede marcar su comentario leído o atendido desde el menú
  **⋮**. Abrir la notificación de la campana también lo marca leído. Abrir el
  calendario o consultar el mes no marca comentarios masivamente.

## Campana y toast

Las confirmaciones de **comentario añadido, marcado como leído y eliminado** utilizan
`toast system-toast` y Bootstrap, con el mismo retraso de ocultación de
**2600 ms** que las confirmaciones de `agenda_reunion.js`. Se retiran las franjas
verdes de guardado y borrado. Los errores de los formularios conservan su
presentación. El autor recibe una confirmación al guardar, sin duplicarla con
el aviso compartido que genera ese mismo comentario.

`recordatorios.js` detecta los comentarios en su consulta habitual de **60
segundos** y muestra la novedad al detectarla, durante **2600 ms**. Si hay otro
toast visible, espera a que termine. La campana permanece hasta abrirlo.
Los avisos ajenos a comentarios conservan su turno de **8 minutos**, la duración
que tengan instalada (incluida la variante de **8 segundos** publicada en
Hostinger), prioridad y rotación. Los comentarios no envían correos ni
notificaciones fuera del CRM.

Sin hora, el comentario genera una novedad informativa: permanece en la campana
hasta su lectura y no se convierte en una alarma periódica. El navegador recupera
su toast desde los comentarios pendientes aunque otra pestaña haya consumido
`avisos` en la sesión PHP. Registra su presentación sólo cuando fue realmente
visible y no repite esa novedad sin hora al recargar la misma pestaña. No se
inventa una hora de medianoche para estos comentarios.

Al abrir un comentario desde la campana o el toast se confirma su lectura con
POST y CSRF antes de retirarlo y navegar. Un fallo conserva la notificación y
permite reintentar. La lectura no elimina el comentario compartido ni atiende
avisos de otros usuarios. Mostrar o cerrar el emergente no marca leído.
Al llegar a Agenda desde una lectura confirmada se muestra el toast de leído,
sin repetir el POST. La marca de confirmación se retira de la URL para no
repetir el toast al recargar.

La integración actual de estos avisos se publica con
`tools/publicar_integracion_toasts_comentarios_hostinger.sh --check` y `--apply`.
Aplica fragmentos únicamente a `recordatorios.js` y `agenda_comentarios.js`,
admite las entregas anteriores y conserva los tiempos de los demás avisos. No
ejecuta SQL ni toca CSS, colores, selección de pestañas, controladores o
permisos. Imprime el respaldo y el comando `--restore`. Se reconstruye con
`python tools/generar_publicador_avisos_comentarios.py --integracion`.
Los publicadores individuales de avisos y confirmación se conservan como
entregas históricas.
Admite también el formato telefónico de Hostinger que añade la clase
`is-unread`; conserva esa clase y su condición de lectura. Las variantes previas
sin integración de comentarios también se comprueban de forma explícita, sin
reemplazar el archivo entero.
La comprobación directa de los archivos publicados también encontró la duración
de 8000 ms, la lectura de `fecha_iso` y `reiterar_hasta_atender`. El publicador
conserva esos tres ajustes y se prueba con esa variante, sin restaurar los valores
de la versión base.

Con hora, también se informa de su creación. Al alcanzar la fecha y hora pasa a
ser un recordatorio pendiente y participa en la rotación periódica hasta que el
destinatario lo atienda, con toast de **2600 ms**. Leerlo antes de su hora no cancela el recordatorio
futuro. La lectura y atención son individuales: no afectan a otros usuarios.
Los vencimientos se calculan con la hora de PHP de la agenda, sin depender de
`NOW()` de MySQL ni de la zona del navegador.

El servicio no consulta comentarios para usuarios sin `reuniones.ver`. La
campana del perfil de consulta se habilita con ese mismo permiso. Las novedades
que queden fuera del límite existente de 12 elementos no se consumen antes de
aparecer en la campana.

## Instalación en Hostinger

El publicador `tools/publicar_agenda_comentarios_hostinger.sh` es autónomo y
contiene sus archivos. No necesita Git, Composer, Node ni Python en Hostinger.
Comprueba sintaxis PHP y aplica fragmentos concretos en cinco archivos existentes,
conservando el resto de su contenido y sus saltos LF/CRLF. Añade ocho archivos.
Rechaza archivos incompatibles o personalizados dentro del nuevo componente;
no reemplaza vistas o controladores completos.

El encabezado de Hostinger incluye reglas adicionales de Marketing y una
campana propia para Convocatorias. El publicador añade únicamente la línea
del permiso de Agenda en la asignación inicial de `$mostrarCentroAvisos`;
conserva literalmente esas reglas y el resto de la vista. La prueba de
publicación incluye el encabezado real facilitado por el usuario, en LF y CRLF.
También admite los fragmentos revisados del controlador de Hostinger con
`puedeTareasAliado` y `AliadoConvocatoriaPendienteService`: conserva su filtro
de Cuenta Clave y el orden que mantiene todas las tareas de Aliados y las
obligaciones telefónicas, además de los 12 recordatorios habituales. No
restaura el servicio anterior de novedades ni reemplaza el controlador entero.
El JavaScript admite la clasificación de vencimientos revisada en Hostinger,
conservando su expresión `esRecordatorioVencidoRecurrente(recordatorio)`.

```bash
bash "$HOME/impe-publicadores/agenda-comentarios.sh" --check
bash "$HOME/impe-publicadores/agenda-comentarios.sh" --apply
php "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_agenda_comentarios.php" --apply
php "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_agenda_comentarios.php" --check
```

El publicador **no ejecuta SQL**. El comando explícito de configuración crea
únicamente `agenda_comentarios` y `agenda_comentarios_lecturas` mediante
`CREATE TABLE IF NOT EXISTS`, y comprueba sus columnas y claves primarias. No
altera tablas previas, usuarios, permisos, credenciales ni reuniones. Si las
tablas todavía no existen, sólo se deshabilitan los comentarios; la agenda y
la campana anterior continúan funcionando.

`--apply` imprime la ruta privada de respaldo y el comando de restauración:

```bash
bash "$HOME/impe-publicadores/agenda-comentarios.sh" --restore RUTA_IMPRESA_DEL_RESPALDO
```

Restaurar revierte los cinco puntos de integración y retira los archivos nuevos
que no existían antes. Rechaza cambios posteriores para evitar sobrescribirlos.
Las dos tablas y sus datos se conservan. El publicador utiliza el mismo bloqueo
de publicación que las entregas anteriores y no incluye configuración privada.

## Validación

Pruebas realizadas sin conectar con el CRM publicado:

- Servicio y SQL en SQLite: autor, permisos, UTF-8, fecha/hora, borrado,
  atención individual, lectura anticipada, límite de campana y tablas ausentes.
- HTTP real de PHP con sesiones aisladas: CSRF, métodos, accesos, autor y
  campana real para consulta/administrador y exclusión sin Agenda.
- MariaDB efímero con `mysqli` real: SQL de instalación, instalación repetida,
  JOIN, NULL, 250 emojis, UPSERT de lectura, hora PHP con SQL UTC y borrado.
- Chrome con peticiones simuladas: editor, hora opcional, texto literal sin XSS,
  borrado propio, cancelación, conservación del borrador ante errores, reuniones
  existentes, escritorio/móvil, enlace desde campana y temporizadores reales.
- Pruebas previas de solicitud/confirmación de reuniones y de toasts existentes.
- Publicador sobre copias LF/CRLF: check inmutable, personalizaciones externas
  conservadas, aplicación idempotente, respaldo/restauración y rechazo de
  incompatibilidades o cambios posteriores. Sin SQL ni red.

Comandos de pruebas:

```text
php tests/agenda_comentarios_test.php
python tests/agenda_comentarios_http_test.py
python tests/agenda_comentarios_http_test.py --hostinger
python tests/agenda_comentarios_mysql_test.py
node tests/agenda_comentarios_browser.cjs
python tests/agenda_comentarios_publicador_test.py
```

Los tests de navegador requieren Playwright y Bootstrap locales; admiten
`PLAYWRIGHT_MODULE_PATH`, `BOOTSTRAP_MODULE_PATH` y `PLAYWRIGHT_CHANNEL=chrome`.
La prueba MariaDB usa una instancia efímera de XAMPP, no su servicio habitual;
admite `IMPE_TEST_MYSQL` y `IMPE_TEST_PHP`. Las pruebas de servicio generan las
respuestas que consume la prueba de temporizadores. Para reconstruir el
publicador tras modificar el componente:
`python tools/generar_publicador_agenda_comentarios.py`.

Queda por aplicar en Hostinger y contrastar con dos sesiones reales con Agenda:
crear sin hora, comprobar campana/toast, crear con hora, atender por un usuario
y comprobar que el otro mantiene su recordatorio, borrar como autor y comprobar
que un perfil sin Agenda no recibe esos avisos. No se han creado comentarios
reales ni enviado mensajes durante estas pruebas.

La consulta mensual admite hasta 500 comentarios por intervalo (máximo 62 días)
y muestra un aviso si se supera ese límite. La campana conserva su límite
existente; los usuarios deben leer/atender pendientes para dar paso a otros.
