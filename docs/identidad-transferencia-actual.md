# Identidad de la transferencia actual

Corrección acotada sobre el PR #89, base `5182be9df3b59f68e0cbada16fd63df4603abe73`.
El PR de corrección tiene como destino la rama de #89 para mostrar únicamente este cambio; no sustituye su publicación pendiente.

## Causa y cambio

La consulta anterior buscaba el contexto por teléfono durante 90 segundos. Una identificación manual no generaba esa fila y podía recuperar el expediente de la llamada anterior. El observador conservaba también la etiqueta si se repetía el número.

Ahora el teléfono envía la referencia de la transferencia actual. El servidor lee su disponibilidad consumida, verifica destinatario, extensión y número, y devuelve esa identidad. El nombre manual mantiene `seguimiento_id = 0`; nunca busca un expediente anterior por teléfono. Sin referencia o evidencia suficiente, devuelve contexto vacío. Las respuestas tardías de otra llamada se descartan. Los alias SDK/PBX de la misma transferencia comparten exclusivamente su identidad actual.

Sólo cambian en producción:

- `app/services/TelefoniaTransferenciaContextoService.php`
- `app/controllers/TelefoniaController.php`
- `public/javascript/telefonia_analista_historial_pasivo.js`

No cambia el envío de transferencias, disponibilidad, tiempos, audio, resultados, grabaciones ni el esquema de base de datos. Los registros históricos se conservan.

## Pruebas relacionadas

- `php -l` de los dos PHP y `node --check` del observador.
- `IMPE_TEST_MYSQL_PORT=PUERTO_AISLADO php tests/telefonia_identidad_transferencia_db.php`: 12 comprobaciones en una base desechable; reproduce AFIPSA seguida de UPEMOR desde el mismo número, conserva la fila anterior vigente y verifica que no se reutilice su expediente. No cargar la configuración del CRM para esta prueba.
- `node tests/telefonia_identidad_transferencia_actual.cjs`: observador real, dos llamadas consecutivas, transición SDK/PBX, referencia tardía y respuesta de la primera llamada recibida durante la segunda. Transporte y DOM simulados, sin llamadas reales.
- Regresiones indispensables: `telefonia_analista_resultado_transferido_smoke.js` y `telefonia_entrantes_bridge_smoke.js`.
- `python3 tests/publicacion_identidad_transferencia_test.py`: cinco escenarios sobre réplicas; GitHub, variante pública Hostinger, PHP personalizado, JS desconocido y PHP incompatible. Comprueba respaldo, idempotencia y rollback exacto; nunca usa el sitio real.
- Workflow dedicado: **Identidad de transferencia actual**, MySQL 8 / MariaDB 10.11.

## Publicación selectiva en Hostinger

Descargar `tools/publicar_identidad_transferencia_hostinger.sh` desde el commit de entrega del PR y guardarlo como `$HOME/publicar-identidad-transferencia.sh`. No usar `git pull` en `public_html`.

```bash
bash "$HOME/publicar-identidad-transferencia.sh" --check
```

Si muestra revisión compatible y no hay llamadas activas, publicar explícitamente:

```bash
bash "$HOME/publicar-identidad-transferencia.sh" --apply
```

El script descarga por HTTPS una versión fija (`8e6226abe2046d50410421b988d9cca3457c3bb4`) en una carpeta privada y prepara exclusivamente los tres archivos. Conserva personalizaciones PHP si el parche encaja y pasa sintaxis; admite únicamente las huellas completas de JS probadas, incluyendo la variante pública descargada el 18/10/2026. Si detecta un conflicto, se detiene sin tocar el sitio. No ejecuta SQL ni necesita Node en Hostinger.

Al aplicar, imprime la ruta del respaldo fuera de `public_html` y el comando de restauración:

```bash
bash "$HOME/publicar-identidad-transferencia.sh" --restore "/ruta/exacta/impresa/del/respaldo"
```

El rollback rechaza archivos editados después de publicar. Si `--check` informa conflicto, conservar los archivos y revisar esa variante; no forzar ni reemplazar archivos completos. El publicador no incorpora ni instala otros cambios del PR #89.

## Comprobación mediante llamadas reales

Con ambas extensiones recargadas y sin llamadas activas:

1. Llamar desde el número compartido, identificar AFIPSA y transferir de 100 a 101. Confirmar AFIPSA en el teléfono de Diego. Terminar la llamada.
2. En menos de 90 segundos, llamar desde el mismo número, elegir **No corresponde**, escribir **UPEMOR** y solicitar disponibilidad. Transferir tras la aceptación.
3. Diego debe ver UPEMOR en disponibilidad y teléfono, incluso después de contestar. AFIPSA no debe reaparecer ni quedar seleccionado su expediente en el registro de la segunda llamada. UPEMOR sigue siendo identificación manual hasta su vinculación explícita.

La validación automática no acredita una llamada real de Zadarma. No se publicó automáticamente en Hostinger.
