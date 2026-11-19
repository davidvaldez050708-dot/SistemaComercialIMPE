# Corrección 403/503 v2: revisión y publicación manual

La pantalla «El servicio está temporalmente ocupado» es una respuesta del manejador
MySQL de Hostinger para 1040/1203/1226/2002/2003/2006/2013. El errno del incidente
real sigue sin estar acreditado por logs. Una muestra Sleep o CPU/RAM bajas no
descarta límites de conexión, workers, transporte ni bloqueos.

Un iframe con una respuesta 403/503, texto plano o HTML incompleto puede mostrar
«No se pudo cargar el módulo». Esa capa no demuestra que falle WebRTC. Se deben
correlacionar URL, status, hora y logs; no eliminar permisos/CSRF para quitar el aviso.

## Dos perfiles compatibles con sus bases

Esta rama modifica solo tres archivos operativos de GitHub, conservando su base:

- Database añade diagnóstico saneado, timeout y compartida() opt-in. Mantiene
  connect(true) por defecto, incluso en CLI, y sesiones independientes por consumidor.
  No añade memoria de fallo global a GitHub: conserva el fallback explícito de
  conexión del historial. Hostinger conserva su memoria de fallo preexistente.
- TelefoniaController omite inicializar catálogo/servicio de extensiones solo en
  POST disponibilidad/POLL, conserva la lectura vigente de permisos y usa compartida
  en ese servicio. RolModel de GitHub mantiene su constructor/conexión independiente.
- Notificaciones comparte cargas simultáneas, conserva/refuerza envío de CSRF y
  bloquea doble clic en marcar todas; no reintenta escrituras.

**No publicar estos archivos raíz sobre Hostinger.** Hostinger tiene personalizaciones
adicionales y ya comparte la autenticación. Su paquete privado conserva los trece
archivos conciliados y solo modifica los tres autorizados. En ese perfil el flujo
modelado de POLL baja de tres conexiones a una; el de GitHub conserva conexiones
adicionales de autenticación. No se promete la misma cifra en ambos.

build_reconciled.py y database_reconciled.php construyen el perfil Hostinger a partir
de una captura SHA256SUMS verificada suministrada privadamente. No se incluyen la
captura, datos, secretos, paquetes de producción ni configuración privada en Git.
El publicador requiere ese paquete y su release.json; falla si faltan o no coinciden.

## Validación disponible

- Contratos HTTP locales con servicios/driver sintéticos: POLL, permisos revocados,
  CSRF, expiración, usuarios/tokens separados, once códigos MySQL y escritura única.
- Notificaciones en VM: coalescencia, doble clic, CSRF, lectura posterior y error sin retry.
- Ciclo de vida del shell y navegación en VM: host único, controles, resultados
  pendientes, origen de mensajes y recuperación del mismo módulo.
- Perfil privado Hostinger: pruebas reales de conexiones sobre MariaDB desechable,
  2/10/20 procesos sintéticos, preservación de persistencia y transacciones independientes;
  respaldo, conflictos, fallo parcial y restauración de archivos en copias locales.
- El usuario confirmó --check y preflight FILES_CHECK_OK en Hostinger actual.
- La comprobación estática antigua telefonia_shell_smoke.php falla en «Auto recepción
  configurable para el usuario» también sobre main sin cambios. Es un fallo previo
  reproducido; no se modifican archivos operativos para ocultarlo. Los demás resultados
  no implican que toda la CI histórica esté aprobada.

```sh
python3 tests/estabilidad_403_503_http_test.py
node tests/notificaciones_403_503_test.cjs
node tests/telefonia_shell_runtime_smoke.js
node tests/telefonia_shell_navegacion_smoke.js
bash -n tools/estabilidad_403_503/hostinger_v2.sh
```

No se probaron llamadas reales, consultas comerciales con datos reales ni un entorno
web equivalente a Hostinger. Las pruebas con transporte/servicios simulados no
acreditan calidad de audio, PBX o estabilidad del proveedor. La prueba antigua de
shell se adapta a la configuración explícita del servidor y admite connection.verified;
no se cambia el shell operativo ni se simula conexión con hostReady por sí solo.

## Diagnóstico y límites

El 503 de responderFalloTemporal lleva X-IMPE-DB-Error-ID. El mismo ID se registra con
errno/SQLSTATE disponible, etapa, persistencia y motivo de una lista cerrada. No se
registran SQL, mensajes completos, cookies o credenciales. Un wrapper con código 0
conserva el errno de la causa mysqli. El log web debe ser escribible.

Un 503 anterior a PHP requiere logs del servidor. Un endpoint que descarte la causa
original tampoco permite recuperarla. Un POST con 503 puede haberse confirmado
antes del fallo: no reenviarlo automáticamente. Retry-After no acredita un rollback.

Las rutas frecuentes ya usan compartida en Hostinger; no se generaliza a modelos
que abren transacciones, consumen órdenes en sesión o cierran conexiones. Se conserva
index.php y todos los métodos de llamadas/transferencias/resultados de ese perfil.
Sesiones, límites, nuevas conexiones/s y consultas de Inicio/Gestión fuera del POLL
pueden seguir contribuyendo. Esta corrección no certifica la causa raíz ni todos los 403.

## SSH compatible, publicación separada de GitHub

La revisión de workflows no identifica un despliegue Hostinger disparado por esta rama/PR.
No se debe fusionar automáticamente. Trabajar sobre el paquete privado de Hostinger,
no con git pull en public_html ni usando los archivos raíz de este PR.

El error /dev/fd/62 apunta a una ruta efímera de descriptor; no se dispone del comando
fallido para precisar su origen. El procedimiento usa archivos guardados y Bash ordinario,
sin mapfile, sustitución de procesos, eval ni dependencia de /dev/fd.

Subir por SFTP el ZIP final y su .sha256 a HOME. Comprobar y extraer fuera de public_html:

```sh
cd "$HOME"
sha256sum -c impe-403-503-v2-final.zip.sha256
test ! -e "$HOME/impe-403-503-v2-final"
mkdir -m 700 "$HOME/impe-403-503-v2-final"
unzip -q "$HOME/impe-403-503-v2-final.zip" -d "$HOME/impe-403-503-v2-final"
cd "$HOME/impe-403-503-v2-final"
sha256sum -c SHA256SUMS.txt
bash hostinger_v2.sh --check
```

El wrapper detecta una única instalación por hashes conocidos en HOME/domains/*/public_html.
Si hay varias, detener y definir IMPE_CRM_ROOT con la ruta real de la instalación aprobada.
El --check valida los 13 archivos, no ejecuta SQL ni escribe en el CRM.

Solo tras autorización posterior y ventana acordada sin llamadas activas:

```sh
cd "$HOME/impe-403-503-v2-final"
mkdir -p "$HOME/respaldos-crm-403-503"
chmod 700 "$HOME/respaldos-crm-403-503"
export IMPE_CRM_BACKUPS="$HOME/respaldos-crm-403-503"
bash hostinger_v2.sh --check
bash hostinger_v2.sh --apply
```

Se respaldan los 13 originales y se sustituyen solo tres, con hashes y permisos
comprobados. Cada rename es atómico; los tres juntos no constituyen una transacción.
No debe existir otro despliegue concurrente. Conservar la ruta exacta de respaldo impresa.

```sh
cd "$HOME/impe-403-503-v2-final"
BACKUP='/ruta/exacta/impresa/por/la/instalacion'
bash hostinger_v2.sh --restore-check "$BACKUP"
bash hostinger_v2.sh --restore "$BACKUP"
```

La restauración acepta originales/candidatos conocidos, recupera instalaciones parciales
y rechaza ediciones posteriores. No modifica BD, sesiones, llamadas, extensiones o grabaciones.
El PHP CLI no invalida OPcache web: resolver antes su política y visibilidad de restauración
con el administrador, sin reiniciar PHP ni forzar recargas de shells activos.

## Dictamen

APTO para revisión de PR y preparación de entrega. **NO APTO para publicación general
sin reservas:** falta validación funcional en staging equivalente, PHP web/OPcache y
escritura del log web. Un piloto controlado requiere autorización expresa aceptando
estas limitaciones, respaldo/restauración accesibles, ventana sin llamadas y capacidad
de detener la prueba ante un 403/503 o inconsistencia. No implica autorizar --apply.
No se promete que desaparezcan fallos de proveedor ni errores en rutas no instrumentadas.
