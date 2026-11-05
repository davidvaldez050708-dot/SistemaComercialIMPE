# Resultado guardado y vinculación técnica

Una llamada de Seguimiento puede terminar en WebRTC antes de que Zadarma publique su identificador. La interacción ya guardada no es un formulario sin guardar. El bloqueo anterior esperaba ambos estados y podía retener al usuario indefinidamente. Además, el puente público instalado emitía `impe:telephony-call-linked` sin el token que exige la protección actual del panel.

Este corte modifica sólo dos JavaScript. Conserva el diseño instalado del Panel de trabajo y no cambia SDK, marcado, transferencia, audio, API PHP, tablas, resultados, crédito de verificación ni permisos.

- Antes de guardar: se mantiene la protección y recuperación del borrador.
- Después del ID definitivo de la interacción: se resguarda una tarea técnica por usuario/token en el navegador. Sólo si esa escritura funciona se libera el panel, se limpia el resumen de esa llamada exacta y se muestra un único toast de éxito.
- La tarea incluye el ID de la interacción y la metadata original. No vuelve a crear la interacción. Se reintenta mientras Seguimiento está abierto y al volver a esa vista. No se elimina por vencimiento de unas horas.
- Si falta el identificador PBX, sólo se intenta recuperarlo durante los primeros 30 segundos, con checkpoint, ventana temporal y sin un marcado posterior observado. Sin evidencia inequívoca queda sin vincular: nunca se toma otra llamada al mismo número ni se inventa un PBX ID.
- Si el intento se canceló antes de existir en la centralita, la interacción permanece guardada. No se afirma que exista una llamada confirmada, una grabación o una verificación. La tarea técnica no equivale a crédito de la meta.
- La protección nativa se registra en una sola ventana, evitando el doble aviso del iframe y su contenedor.

Las tareas pendientes residen en ese navegador. Borrar sus datos o cambiar de equipo puede perder el reintento local, aunque la interacción guardada en el servidor permanece. Este corte no incorpora un conciliador de servidor ni ejecuta SQL.

## Instalación controlada

Descargar `tools/publicar_analista_cancelacion_inmediata.py` y `tools/analista_cancelacion_inmediata_release.json` del mismo commit en un directorio privado. El manifiesto contiene únicamente variantes exactas de Git y las dos versiones públicas comprobadas. Variantes desconocidas se rechazan antes de crear respaldos o escribir en el sitio.

```bash
python3 publicar_analista_cancelacion_inmediata.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-analista-cancelacion" --check
```

Después de `CHECK OK`, repetir con `--apply`. El instalador crea un respaldo privado, publica sólo esos dos JS con permisos 644 y admite `--restore RUTA_DEL_RESPALDO` si no hubo cambios posteriores. Compatible con Python 3.6.8. No ejecuta SQL ni llama al CRM.

Probar sin llamada activa: recargar el CRM, llamar desde Seguimiento a un número de prueba, colgar antes de respuesta, guardar «Otro / me equivoqué», comprobar un único éxito y poder cerrar/navegar/recargar. Repetir con una conversación y con dos llamadas consecutivas al mismo número. La segunda debe empezar desde cero y conservar su resultado propio. La duración y respuesta técnica no se deducen de la selección del asesor.

## Validación automatizada

`analista_cancelacion_inmediata_browser.cjs` usa la vista PHP real, Bootstrap y proveedor simulado: cancelación inmediata sin PBX, ID tardío tras recarga, dos eventos de guardado, estado tardío, llamadas consecutivas, navegación sin diálogo repetido y fallo seguro del almacenamiento.

Se ejecutan también la protección del panel, verificación vigente, recepción transferida, horario de contacto y publicación reversible en directorios desechables. No se realizan llamadas reales ni importaciones.
