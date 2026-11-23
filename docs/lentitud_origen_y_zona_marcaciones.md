# Lentitud intermitente y consultas de zona horaria — 2026-11-23

## Evidencia de producción

El usuario informa demoras generalizadas aunque el perfil posterior registra
`territorio.index` en 23.79 ms, `login.iniciarSesion` hasta 86.48 ms y varias rutas
en decenas de milisegundos. Persisten detalleVenta (mediana 705.57 ms, SQL 245)
y recordatorios (547.37 ms, SQL 298). Algunos endpoints todavía esperan casi un
segundo al abrir la sesión. El usuario revisó hPanel y reportó que los recursos
están por debajo de sus límites; no se asume saturación del plan.

Se confirmó por el conector Hostinger que la aplicación es PHP 8.2.36, OPcache
está habilitado, HTTPS está activo, CDN está activo y caché de servidor está
desactivada. No se cambió ninguna de estas opciones ni los registros DNS.

Lecturas HTTP anónimas desde esta máquina:

| Lectura | Tiempo al primer byte | Evidencia |
|---|---:|---|
| Raíz del dominio | 12.573 s | HTTP 200, TLS 0.127 s |
| Login | 12.108 s | HTTP 200, TLS 0.119 s |
| Login con cabeceras de diagnóstico | 10.556 s | upstream 10.269 s; fases hasta despacho ~2.63 ms |
| CSS de la aplicación | 0.202–0.314 s | HTTP 200 |
| Otras lecturas de login por CDN | 0.251–1.970 s | Demora intermitente |
| Acceso directo con SNI a IP del registro FTP | 0.193–0.240 s | LiteSpeed, certificado verificado, mismo perfil PHP |
| Acceso directo con SNI a IP del nombre SSH | 0.193–0.213 s | LiteSpeed, certificado verificado, mismo perfil PHP |

La lectura lenta identificable durante las pruebas del 23 de noviembre fue:
`x-hcdn-request-id: a94bcaa1340904992e20ec857869e8a1-imm-edge9`.
La petición comparable rápida: `6590c85f94cb8536362475c213f882b4-imm-edge10`.

Las IP de prueba se obtuvieron del registro DNS FTP (82.180.138.104) y de resolver
el nombre de servidor SSH (31.97.208.126). Ambas devolvieron el login del sitio y
sus cabeceras de perfil. Esto no prueba cuál dirección usa internamente el CDN
ni que la causa sea exclusivamente el CDN: falta una observación simultánea de
un fallo público y un origen rápido, o trazas de Hostinger. En diez pares de
comparaciones simultáneas posteriores, ambas rutas respondieron rápido; seis
pares se separaron siete segundos entre sí. El origen también
puede tener colas o demoras previas al inicio de la aplicación. No se justifica
activar caché global de HTML autenticado ni cambiar el plan por esta evidencia.

## Corrección de una repetición confirmada en el código instalado

`TelefoniaMarcacionesVentasService::reconciliarPendientes` convertía dos veces
la fecha de cada marcación pendiente, consultando el offset MySQL en cada
conversión. N pendientes podían causar 2N lecturas idénticas de zona horaria,
incluso cuando ninguna llamada recibía nueva evidencia. Esto explica un origen
de consultas repetidas que seguía después del primer paquete de metadata.

El cambio lee la zona una vez por revisión y reutiliza los instantes calculados.
El método privado admite una zona explícita; los demás consumidores siguen
consultando la zona actual. No hay caché global ni entre peticiones. Se conservan
los límites -5/+30 segundos, ambigüedades, verificación de extensión, propietario,
destino, exclusión de llamadas institucionales y protección contra duplicados.

Se utilizó una copia completa del archivo instalado, obtenida mediante la lectura
de Hostinger. El publicador sustituye únicamente tres bloques de ese archivo,
conserva el resto y respalda fuera de public_html. Es compatible con Python 3.6.

## Validación

- `py -3 tests/zona_marcaciones_release_test.py`: bloques instalados, lint PHP,
  CRLF, idempotencia, preservación de personalizaciones, conflicto sin escritura,
  respaldo/restauración y activación de una nueva ventana de perfil.
- `php tests/zona_marcaciones_db_test.php <clave-local>`: MariaDB local, esquema
  desechable. En UTC y -06:00, 92 marcaciones pasan de 259 a 76 consultas con los
  mismos ocho enlaces. Incluye intentos sin evidencia, candidatos ambiguos en
  ambas direcciones, llamadas institucionales, otro usuario y segunda ejecución.
  También comprueba cruces de día, fecha inválida y cambio explícito de la zona
  MySQL en el mismo objeto. No contacta con Zadarma ni inicia llamadas.

La reducción sintética no equivale al tiempo total de navegación en producción.
Debe medirse después de instalar. La demora HTTP anterior a PHP requiere seguir
contrastando el origen, la capa CDN y los registros del proveedor.

## Consulta preparada para soporte de Hostinger

> En fundacionrededumex.org hay latencia intermitente antes de responder páginas
> dinámicas. El 23/11/2026 capturamos login GET HTTP 200 con TTFB 10.556 s,
> x-hcdn-upstream-rt 10.269 s y fases Server-Timing hasta despacho de ~2.63 ms.
> Request ID: a94bcaa1340904992e20ec857869e8a1-imm-edge9. Otra captura rápida:
> 6590c85f94cb8536362475c213f882b4-imm-edge10. El perfil PHP del CRM y las
> comprobaciones SQL no explican esa demora; hPanel muestra recursos por debajo
> del límite. Las lecturas directas con SNI y TLS verificado respondieron en
> ~0.2 s. Solicitamos revisar trazas de conexión CDN/origen, reintentos y cola
> LiteSpeed/LSAPI. No se han cambiado DNS, versión PHP ni caché durante la prueba.

Es un borrador para que el usuario lo envíe; no se abrió ni envió ningún ticket.
