# Recordatorios comerciales V3 sobre las bases reales de Hostinger

La comprobación inicial bloqueó correctamente porque la rama base no contenía
los cambios ya instalados de alcance territorial, Agenda, comentarios, telefonía
y encabezado. Esta entrega se construye a partir de los seis SHA-256 y los diffs
proporcionados por el usuario el 18 de noviembre de 2026.

La fixture `tests/fixtures/recordatorios_hostinger_20261118.patch.json` permite reconstruir
las seis bases desde `a04beeb6d9b81f8e868ea8fdf5b40e77825c41b5`. Cada reconstrucción
debe coincidir exactamente con el SHA-256 informado antes de utilizarse en pruebas.
No se acepta una personalización desconocida únicamente por parecer similar.

## Archivos conservados

Estos cuatro archivos se verifican contra sus bases auditadas y no se publican:

- `app/helpers/ReminderHelper.php`: mantiene `SeguimientoTerritorialScope` y el
  filtro de propiedad territorial del Analista.
- `app/services/AgendaReunionService.php`: conserva la agenda por permisos,
  lectura, comentarios, solicitudes y sus avisos de horario.
- `app/views/layout/topbar.php`: conserva una sola campana de Marketing, CSRF,
  lectura de avisos telefónicos y menú lateral plegable. Ya permite la campana
  del asesor; no necesita la modificación de la rama anterior.
- `app/views/layout/dashboard_footer.php`: conserva los scripts instalados.

El controlador y `recordatorios.js` tienen artefactos adaptados en
`tools/recordatorios_comerciales_v3_hostinger/after/`. Se mantienen las notificaciones
de comentarios, sus acuses y lectura con CSRF; horarios y desfase de servidor;
obligaciones telefónicas leídas pero pendientes; tareas de Cuenta Clave y sus
permisos. Se añade el flujo comercial sin truncar sus tareas. Cuando hay actividades
comerciales, se respeta la prioridad de vencidos al combinar los grupos.

Los proveedores existentes de notificaciones telefónicas permanecen sin cambios.
El controlador adaptado conserva sus llamadas y campos actuales en lugar de exigir
que sean reemplazados por copias antiguas de la rama.

## Tiempos reales conservados

- Analista: 3 horas, 1 hora, 10 minutos y vencimiento.
- Agenda: 30, 20, 10, 5 y 1 minuto, por su mecanismo de horarios existente.
- Duración institucional instalada: **8 segundos**.
- Novedades de comentarios: **2.6 segundos**, independientes del recordatorio general.
- Rotación general de pendientes: un turno cada ocho minutos; consulta cada minuto.

La referencia anterior a siete segundos correspondía a la rama base, no a esta
versión de producción. La adaptación mantiene los ocho segundos informados por
el archivo real. Las actividades comerciales mantienen el ciclo ya probado de
programar, reprogramar, vencer y completar; no envían llamadas ni mensajes.

## Pruebas

- Reconstrucción de las seis bases y comparación de SHA-256.
- Once escenarios comerciales sobre el JavaScript adaptado, con duración de 8 s.
- Once escenarios de regresión del presentador institucional sobre esa misma versión.
- Diez escenarios específicos de Agenda: anticipaciones 30/20/10/5/1, duración
  de 8 s, comentarios 2.6 s, lectura `leer`/`leerVisto` con CSRF y pendientes
  telefónicos leídos que requieren atención. También se comprueba que un aviso de
  sesión de Agenda no se pierda cuando se descarta una respuesta atrasada por un
  cambio comercial.
- Controlador adaptado real con proveedores sintéticos: seis perfiles, 85 tareas
  comerciales, 25 obligaciones telefónicas, comentarios, acuses, horarios y Aliados.
- Publicación selectiva desde las bases exactas: CHECK, hashes de artefactos,
  bloqueo de cambios desconocidos, respaldo, APPLY idempotente, rollback y fallo
  parcial. Se comprueba que los cuatro archivos conservados permanecen idénticos.
- La misma prueba de publicación con comprobación real de metadatos en una base
  MariaDB desechable creada por `supervision_comercial_v3_db.php`.

Las pruebas no utilizan la base operativa ni ejecutan llamadas reales. Los
proveedores sintéticos del controlador comprueban el contrato de integración;
no simulan que se hayan probado consultas de comentarios sobre datos reales.

## Publicación

Usar `tools/publicar_recordatorios_comerciales_v3_compatible_hostinger.py`.
El publicador identifica la base exacta del controlador y selecciona la variante.
En Hostinger crea una proyección privada de seis archivos, con hashes fijos de los
dos artefactos adaptados. No aplica parches abiertos sobre producción ni omite la
comprobación de los archivos que va a sustituir. También mantiene la protección
de la variante revisada de `TelefoniaResultadoVentasService.php`.

CHECK no escribe en la base ni publica código. APPLY conserva y verifica el código
anterior antes de publicar. No hay migración SQL. Una diferencia desconocida en
las bases auditadas, archivos a publicar o artefactos del paquete bloquea la operación.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PUBLICADOR="$ENTREGA/tools/publicar_recordatorios_comerciales_v3_compatible_hostinger.py"
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" \
  --backup-root "$HOME/respaldos-recordatorios-comerciales-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

Para revertir, utilizar el mismo paquete y la ruta exacta `ROLLBACK_BACKUP`:

```bash
python3 "$PUBLICADOR" --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE
```

La reversión repone las versiones de producción auditadas, incluidos sus comentarios
y horarios, y bloquea si un archivo publicado recibió una personalización posterior.
Después de publicar, cargar una vez con Ctrl+F5. La primera prueba es programar una
actividad ficticia y comprobar su aparición en la campana sin recargar. Los toast
comerciales mantienen el turno general de ocho minutos; no deben confundirse con
los avisos específicos de horario de Agenda.
