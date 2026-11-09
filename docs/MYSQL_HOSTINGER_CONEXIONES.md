# Estabilización de conexiones MySQL en Hostinger

Base de comparación `a90b6ade` de `origin/main`. La publicación se prepara
como integración selectiva sobre los archivos instalados. La rama no debe
usarse para sustituir el directorio público completo.

## Auditoría

- En `app`, `config` y `prueba_telefonia`, el código PHP de ejecución abre
  MySQL mediante `Database`. La búsqueda de constructores y del driver cubrió
  91 archivos con referencias directas en esta rama. Las herramientas de
  instalación y sus bases temporales quedan fuera de la política web.
- Los cierres revisados son principalmente de sentencias preparadas, lectores
  y archivos. Los modelos con `begin_transaction`, los servicios con
  `GET_LOCK` y los demás consumidores sin revisión de propiedad de sesión
  conservan conexiones independientes. Los llamadores que ya inyectan una
  conexión siguen controlando la frontera de su transacción.
- Inicio de sesión y validación autenticada reutilizan la conexión en las
  lecturas consecutivas de usuario y permisos. Estado de llamada, entrada
  activa, expediente y lectura de grabaciones comparten conexión en los
  recorridos revisados. La autorización de transferencia reutiliza una
  conexión entre la comprobación de disponibilidad y la de enrutamiento.
- La disponibilidad conserva la escritura de presencia y la reconciliación
  de transferencias. Durante un sondeo evita repetir la lectura de extensión
  y la actualización de expiraciones. El navegador conserva el sondeo de
  disponibilidad de 3 s y consulta devoluciones cada 15 s. Los clientes
  anteriores mantienen su respuesta original.
- Las consultas entrantes conservan 1,8 s en condiciones normales. El estado
  saliente usa una separación mínima de 1,2 s mientras timbra y 2,2 s durante
  la conversación. Se conserva el control del SDK y la comprobación del token
  de llamada. Los sondeos aplican espera progresiva acotada con dispersión
  ante fallos. Un 503 durante la transferencia no se interpreta como un
  resultado de la llamada. No se reintenta automáticamente el envío al PBX.
- Los constructores de disponibilidad, operación de transferencia y
  almacenamiento de webhooks dejan de ejecutar DDL. Extensiones mantiene su
  provisión administrativa y comparte el resultado de la comprobación de
  tabla dentro de la petición. El publicador verifica el esquema requerido.

## Política de conexiones

`Database::compartida()` conserva una sola referencia por petición. Inicio
del Asesor y el sondeo de disponibilidad reutilizan esa referencia en sus
consultas secuenciales. Tras observar 503 durante el tráfico normal, se
restableció la persistencia web predeterminada de las conexiones
independientes. `connect(false)` sigue abriendo una sesión no persistente.
`IMPE_DB_INDEPENDENT_PERSISTENT=0` permite ensayar la otra política de forma
controlada. `IMPE_DB_PERSISTENT=0` controla el pool de la conexión compartida.
CLI abre conexiones no persistentes por defecto.

Cada proceso PHP mantiene su propio pool. Acordar con Hostinger un número
efectivo de procesos y un máximo de una conexión persistente por proceso
(`mysqli.max_persistent=1` en la configuración de servidor, si está
disponible). Reservar margen bajo 75 para transacciones simultáneas y tareas
externas. El límite documentado de nuevas conexiones es 20/s por cuenta.
Medir ambos límites antes de cambiar la política. Un proceso viejo puede
retener conexiones del código anterior hasta que Hostinger lo recicle.

Los fallos de conexión reconocidos devuelven 503 con `Retry-After: 3`, sin
mensajes del driver en la respuesta. Una conexión temporalmente rechazada
no se vuelve a abrir en esa petición. Los rechazos de sesión y permisos
mantienen sus estados. El webhook conserva su bitácora privada y devuelve
503 si no pudo persistir por indisponibilidad temporal. El índice de hash
existente protege la persistencia cuando se recibe el mismo evento otra vez.

## Resultados en entorno aislado

- MariaDB local, tres trabajadores simultáneos, dos lecturas lógicas por
  trabajador. El patrón de sesiones independientes mantuvo 6 conexiones
  y el compartido 3, excluyendo el observador en ambos casos. Al finalizar
  los trabajadores quedaron 0 conexiones de esos trabajadores. El ensayo
  usa PHP CLI y no mide la persistencia entre procesos LiteSpeed.
- En PHP HTTP local, una interrupción de conexión devolvió 503 y
  `Retry-After: 3`. El endpoint de operaciones de transferencia devolvió
  401 sin sesión y 403 con sesión sin permisos. No se validó un inicio de
  sesión real con cuentas de producción.
- Los ensayos de identidad de transferencia (12 comprobaciones en MariaDB),
  autorización de salientes, seguridad de transferencias y transición SIP
  pasaron. La simulación de transporte verifica recuperación tras 503
  sin convertirlo en un resultado de transferencia.

## Verificación y publicación

1. Guardar una línea base con tráfico normal. Agrupar conexiones por
   `COMMAND` usando `information_schema.PROCESSLIST` con el usuario de la
   aplicación. Registrar picos totales y `Sleep`, aperturas por segundo con
   métricas de Hostinger, latencia y 503 por ruta. Tomar ventanas comparables
   con el mismo número de operadores. Evitar pruebas de carga en producción.
2. Comprobar en la base de datos las migraciones ya existentes
   `2026_10_06_e_telefonia_extensiones_multiusuario.sql`,
   `2026_10_07_b_zadarma_webhook_eventos.sql`,
   `2026_10_07_c_zadarma_entrantes_indice.sql`,
   `2026_10_18_disponibilidad_devoluciones.sql` y
   `2026_10_18_transferencia_operaciones_notificaciones.sql`.
   El publicador hace consultas de solo lectura. Si falta una estructura,
   revisar la migración idempotente correspondiente por separado.
3. Desde una copia separada de la rama, ejecutar
   `python3 tools/publicar_mysql_hostinger.py --site "$HOME/domains/fundacionrededumex.org/public_html" --check`.
   El informe compara las huellas y las personalizaciones con GitHub,
   verifica la integración y ejecuta el análisis sintáctico PHP. Un
   conflicto se detiene sin modificar el sitio. Revisar los fragmentos
   personalizados antes de autorizar el despliegue.
4. Aplicar en ventana controlada con el mismo comando, sustituyendo
   `--check` por `--backup-root "$HOME/respaldos-impe" --apply`. Conservar
   la ruta impresa. Para revertir, usar `--restore RUTA_DEL_RESPALDO`.
   La restauración comprueba la huella del parche y los archivos vigentes.
5. Contrastar el acceso, revocación, permisos y los flujos de telefonía con
   el registro normal de operación. Verificar también historial, grabaciones,
   agenda y módulos comerciales sin generar llamadas de prueba contra
   producción. Repetir las mediciones del paso 1. Si aumentan los 2002 o
   empeora la latencia, conservar la evidencia y revertir la entrega.

## 403 históricos

Una respuesta de 787 bytes no identifica la capa que la generó. Correlacionar
hora con zona, método, ruta, tamaño, código, cabeceras y el identificador CDN
con los registros de acceso de Hostinger y los eventos PHP `[impe_mysql]`.
Separar los 403 del CDN o WAF, los 403 de sesión/permisos y los fallos de
conexión 503. En el incidente anterior se observó 403 por el CDN incluso en
un CSS público, con 200 directo al origen durante la investigación. Falta la
traza original de las respuestas de 787 bytes para atribuirlas.

Fuentes de la política

- [Límite de conexiones nuevas de Hostinger](https://www.hostinger.com/support/how-to-fix-mysql-operation-not-permitted-error/)
- [Configuración de conexiones persistentes en PHP](https://www.php.net/manual/en/mysqli.configuration.php)
