# Tarjetas comerciales compactas V3

Base instalada: `de32d205d44fdacceb31d4b37bcd03d4f159d1d4`.

Se mantienen **todas las subetapas**, incluido Pendiente de primera llamada,
conforme a la indicación del usuario. Se conservan nombre, origen, programa,
asesor en supervisión, horario solicitado, intentos y acciones.

- Nuevos muestra `0/3 · Retomar contacto` en una línea cuando corresponde.
- La próxima actividad reúne tipo y fecha: `Llamar · 19 nov, 10:00 a. m.`.
  Si está vencida, el texto comienza por Vencida. El nombre de la tarea puede
  abreviarse con puntos suspensivos; la fecha y hora permanecen visibles.
- Se omite el año sólo para fechas del año actual en México. El tooltip y el
  expediente conservan la fecha completa y el detalle de la tarea.
- Se retiran de la tarjeta la jornada y la última actividad completada. El
  expediente conserva ambos datos. Los metadatos usados por los formularios
  siguen intactos, incluidas versión, fecha pendiente, tipo y detalle original.
- Si existe un evento V3, se presenta su pendiente vigente y no otra fecha de
  la agenda anterior. Las fichas sin eventos V3 conservan su agenda o revisión.

La entrega sólo cambia la plantilla operativa compartida, su CSS y el formato
de fecha de presentación. No modifica SQL, etapas, subetapas, actividad guardada,
resultados de llamadas, notificaciones ni permisos.

Validación: renderizado con MariaDB aislada y datos sintéticos; navegación de
asesor y supervisión; acciones existentes, programación, reprogramación,
permisos y anchos de 1920/1440/1280/390 px; inspección visual y comprobación de
hashes, bloqueo de personalizaciones, respaldo, apply idempotente y rollback.

Con el paquete en una carpeta privada:

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PUBLICADOR="$ENTREGA/tools/publicar_tarjetas_compactas_v3_hostinger.py"
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" \
  --backup-root "$HOME/respaldos-tarjetas-compactas-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

El publicador reutiliza las verificaciones de esquema de la entrega anterior
en modo lectura; no ejecuta su ampliación SQL. Conserva la variante revisada
de Telefonía. Ante diferencias desconocidas bloquea sin sobrescribir.

Para revertir, usar el mismo paquete y la ruta exacta ROLLBACK_BACKUP:

```bash
python3 "$PUBLICADOR" --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE
```

Después de aplicar, Ctrl+F5 una vez. Comprobar que la tarjeta ficticia conserva
su subetapa, muestra la tarea y su horario en una línea, y permite abrir y
reprogramar la actividad con su fecha original precargada.
