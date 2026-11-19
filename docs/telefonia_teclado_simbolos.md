# Símbolos del teclado de extensión

Entrega selectiva sobre las cuatro versiones auditadas de Hostinger el 19 de noviembre de 2026. Añade `*` y `#` al teclado existente del modal de llamada, tanto en el shell persistente como en el panel clásico, con distribución `123 / 456 / 789 / *0#`. Funciona por botones y teclado físico mientras el panel esté abierto; conserva Escape, los modificadores y la protección contra repetición del teclado.

Se mantienen la configuración por extensión, el permiso para usar telefonía y las comprobaciones de llamada activa, conversación establecida, token vigente y ausencia de transferencia en curso. El host admite un único símbolo o dígito por comando. WebRTC conserva los tonos manuales pendientes sin reemplazarlos al pulsar rápido, limita su cola y rechaza mezclarla con una transferencia. No cambia las reglas de números de destino ni los permisos del sistema.

Sólo se publican estos archivos:

- `public/javascript/telefonia_shell.js`
- `public/javascript/telefonia_persistente.js`
- `public/javascript/telefonia_host.js`
- `public/javascript/telefonia_webrtc_dtmf.js`

No modifica estilos, configuración privada, PHP, SQL, contactos, sesiones o resultados de llamadas. Se conservan las personalizaciones de conexión, audio, rechazos, controles y resultados de las bases auditadas. Los demás roles que ya dispongan del teclado lo reciben por el mismo mecanismo autorizado; no se concede telefonía a un rol que carezca de ella.

`tools/publicar_teclado_simbolos.py` valida el manifiesto fijado por SHA-256 y las cuatro bases exactas. CHECK sólo lee archivos y comprueba el runtime. APPLY respalda originales, verifica hashes, publica con sustituciones atómicas y revierte automáticamente un fallo parcial; repetirlo no crea otro respaldo. `--restore RUTA_EXACTA` recupera los originales comprobando que no haya ediciones posteriores. El respaldo debe estar fuera de `public_html`.

```bash
python3 tools/publicar_teclado_simbolos.py --site "$SITIO" --backup-root "$PRIVADO" --check
python3 tools/publicar_teclado_simbolos.py --site "$SITIO" --backup-root "$PRIVADO" --apply
python3 tools/publicar_teclado_simbolos.py --site "$SITIO" --backup-root "$PRIVADO" --restore "$RESPALDO_EXACTO"
```

Pruebas:

- `node tests/telefonia_webrtc_dtmf_smoke.js`: canal único, conexión, cola y símbolos, valores inválidos y separación de transferencias.
- `node tests/telefonia_simbolos_browser.cjs`: DOM y eventos reales de ambos teclados con el guard del host y puente WebRTC; botones y teclado físico, orden, modificadores, cierre, Escape y rechazo por referencia/estado. Usa Playwright/Edge a través de PLAYWRIGHT_MODULE_PATH y CHROME_PATH; el peer es sintético, sin SIP ni tráfico a Zadarma.
- `python tests/telefonia_simbolos_publicacion.py`: sitio sintético con bases idénticas a las auditadas, CHECK sin escrituras, bases desconocidas, respaldo, idempotencia, reversión, ediciones posteriores y fallo parcial.

Después de aplicar, cargar el cliente nuevo cuando no haya una llamada activa. Comprobar en una llamada de prueba autorizada a un menú telefónico que `*`, `0` y `#` producen la respuesta esperada. La aceptación de tonos por WebRTC no acredita por sí sola la respuesta del menú remoto.
