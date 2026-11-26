# Reportes de Seguimiento de Vinculación

Entrega selectiva preparada desde los archivos instalados en Hostinger. El manifiesto conserva los hashes de esa versión y rechaza otro contenido; no sustituye el sitio completo ni ejecuta migraciones.

Correcciones:

- El servidor oculta el formulario desde el HTML inicial sólo cuando el reporte se generó sin errores. El formulario del modal permanece visible y conserva sus selecciones. El CSS bloqueante refuerza ese estado; los assets existentes ya usan `filemtime`.
- Se elimina el límite silencioso de 200 expedientes. Una fuente de lectura compartida concilia resumen, rendimiento diario, instituciones y autores con los mismos filtros.
- Los intentos PBX finalizados sólo se incorporan con referencia exacta y extensión acreditada. Los intentos sin expediente se limitan a Actividad global sin filtros institucionales, sin inventar contacto, verificación ni historial. Las asociaciones no usan teléfonos.
- Se conserva la regla comercial instalada de verificación explícita, proveedor y referencia no vacíos y duración positiva; se deduplica por autor, institución y día local.
- Se normalizan únicamente las interacciones WebRTC documentadas como UTC; las demás fechas locales se conservan. El autor seleccionado se transmite a la analítica y el navegador reutiliza el resumen del servidor.
- El PDF usa el mismo servicio y alcance; una nueva versión de su clave evita reutilizar los ceros del caché anterior.

## Verificación

- 76 aserciones PHP con datos sintéticos: más de 200 expedientes, autores mezclados, permisos, llamadas entrantes/salientes/transferidas con referencias exactas, alias SDK/PBX de transferencias entregadas, duplicados, instituciones, fechas UTC/México, contacto y efectivas, integración de los argumentos reales HTML/PDF.
- 48 aserciones sobre la vista PHP real: Actividad, Cartera e Institución, generación correcta, modal, error, estado inicial sin JavaScript y selecciones conservadas.
- 10 aserciones JavaScript sobre las funciones reales: reutilización del resumen del servidor, todos los IDs, responsable, canal y fechas; sin nueva solicitud de analítica cuando existe ese resumen.
- 38 comprobaciones del publicador en directorio temporal, con `proc_open`, `exec`, `shell_exec`, `passthru` y `system` deshabilitados. CHECK sin reemplazos, rechazo de diferencias en archivos y dependencias y PHP inválido, respaldo privado, APPLY y ROLLBACK byte por byte; rechazo de cambios posteriores. Las cuatro dependencias se verifican sin reemplazarlas.
- Navegador local: formulario oculto sin el JavaScript diferido; «Editar filtros» abre el iframe y conserva fecha y responsable. El entorno usa datos sintéticos y un adaptador de modal; no representa una sesión autenticada de producción.

El PDF binario no fue renderizado en esta prueba: se comprobaron los datos que recibe el exportador y su clave de caché. No se hicieron llamadas reales de prueba, cambios de datos, reemplazos en producción ni pruebas de WebRTC activo.

## Uso privado en Hostinger

Descargar a una carpeta nueva `impe-private/reportes-vinculacion-XXXXXX`, fuera de `public_html`. Ejecutar `check.sh COMMIT SITIO PRIVADO PAQUETE`; el script descarga y verifica las herramientas y los ocho archivos, ejecuta CHECK y guarda el indicador del paquete.

Para conciliar el candidato **sin instalarlo**:

```bash
php "$PAQUETE/conciliar_candidato.php" --site="$SITIO" --fecha=2026-11-25 --actor=5
```

La conciliación usa una transacción `READ ONLY`, una conexión compartida y dependencias de la instalación; crea y retira únicamente una copia temporal privada. No muestra teléfonos, notas ni nombres de instituciones o personas. No afirmar un total adicional de PBX hasta revisar esta salida: la clasificación por sí sola no prueba que la llamada haya finalizado en la extensión indicada.

Sólo tras autorizar la instalación:

```bash
bash "$PAQUETE/apply.sh" "$SITIO" "$PRIVADO"
```

APPLY respalda todos los originales antes del primer reemplazo y conserva los permisos de archivo. Guarda el identificador y la carpeta del paquete para poder revertir:

```bash
bash "$PAQUETE/rollback.sh" "$SITIO" "$PRIVADO"
```

ROLLBACK comprueba el recibo y los hashes; rechaza sobrescribir modificaciones más recientes. No hay archivos SQL, llamadas a proveedores ni nuevos sondeos.
