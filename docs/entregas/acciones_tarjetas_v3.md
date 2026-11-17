# Accesos directos por etapa en las tarjetas V3

Base instalada: `0e62dafa280f405a8f008640c7193bf9feae4448`, incluida la corrección de Todos los asesores y la personalización de telefonía revisada.

## Resultado

| Etapa | Accesos en la franja de la tarjeta |
|---|---|
| Nuevos | Llamar, WhatsApp reservado, Actividad, Más |
| Contactados | Llamar, WhatsApp reservado, Actividad, Más |
| Potenciales | Llamar, WhatsApp reservado, Actividad, Gestionar avance, Inscripción, Más |
| Seguimiento | Llamar, WhatsApp reservado, Actividad, Inscripción, Más |
| Proceso de Inscripción | Llamar, WhatsApp reservado, Actividad, Gestionar inscripción, Más |

Cada acción conserva las condiciones de disponibilidad y permisos. Llamar requiere teléfono y autorización. Supervisión mantiene únicamente consulta. No contactar no ofrece llamadas, WhatsApp ni los accesos operativos nuevos. Los códigos históricos que no admiten estas operaciones no muestran botones adicionales; INTERESADO conserva las acciones compatibles de Potenciales.

El usuario eligió **reservar WhatsApp por ahora**. Se representa con un botón deshabilitado y la descripción «WhatsApp · Próximamente». No tiene enlace, controlador, integración ni envío asociado. Este cambio no usa WhatsApp Web ni la API del CRM.

El expediente del asesor continúa abriéndose desde el nombre del prospecto. Más conserva Mis llamadas y las decisiones secundarias permitidas. Se retiran del menú las operaciones promovidas a la franja para evitar duplicados. El acceso de consulta al expediente del supervisor permanece en su menú.

Actividad reutiliza el formulario existente: sin pendiente, Registrar/Programar; con pendiente, Registrar/Reprogramar/Completar. El icono distingue si hay una actividad pendiente. Gestionar avance mantiene el formulario de subetapas de Potenciales. También se corrige su ayuda para indicar que programar una próxima acción mueve a Seguimiento, conforme al comportamiento ya publicado.

Se conservan tamaño, colores y estilos institucionales. Los accesos se alinean al inicio y Más al final. La fila puede ajustarse en varias líneas en tarjetas estrechas sin recortar botones.

## Validación

- `supervision_comercial_v3_db.php`: renderiza las plantillas reales con datos sintéticos y verifica accesos por etapa, historial INTERESADO, pago histórico, No contactar, permisos reducidos y supervisor. Conserva las comprobaciones existentes de filtros, enlaces, historial y cero escrituras durante las consultas.
- `acciones_tarjetas_v3_browser.cjs`: comprueba los accesos directos de cinco etapas, opciones según cita pendiente, formularios de avance e inscripción, ausencia de duplicados y de acceso redundante al expediente. Ejecuta los manejadores reales con HTTP sintético para comprobar CSRF, una petición por guardado, versión tras actualizar tarjetas y teléfono estable. WhatsApp deshabilitado no genera solicitudes ni abre ventanas. Revisa anchos de 1920, 1440, 1280 y 390 píxeles.
- `supervision_comercial_v3_browser.cjs`: regresión de Supervisión y navegación con Todos los asesores y asesor seleccionado.
- Prueba del publicador con `IMPE_PUBLICADOR_MODULO=publicar_acciones_tarjetas_v3_hostinger`: integridad, respaldo, aplicación idempotente, rollback, fallo parcial y protección de personalizaciones.

Las capturas de QA utilizan la misma versión de Bootstrap Icons que carga el encabezado del CRM. No se publica ninguna fuente ni dependencia nueva.

## Publicación

`tools/publicar_acciones_tarjetas_v3_hostinger.py` publica únicamente la vista `operativo_v3.php` y su CSS. No modifica JavaScript, PHP de servicios/controladores, permisos, SQL, telefonía ni datos. Verifica las bases exactas y conserva la variante de telefonía de Hostinger.

Con el paquete extraído en una carpeta privada:

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PUBLICADOR="$ENTREGA/tools/publicar_acciones_tarjetas_v3_hostinger.py"
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" \
  --backup-root "$HOME/respaldos-acciones-tarjetas-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

El motor compartido conserva sus mensajes y confirmaciones anteriores. El manifiesto y el respaldo contienen únicamente los dos archivos de esta entrega. Ante personalizaciones desconocidas se bloquea; no forzar hashes ni copiar por encima.

Para revertir solo el código, usar el mismo publicador y su ruta exacta `ROLLBACK_BACKUP`:

```bash
python3 "$PUBLICADOR" --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE
```

Después de publicar, cargar una vez con Ctrl+F5. Con el asesor 22 comprobar los accesos de cada etapa, abrir Actividad directamente y registrar una gestión en una ficha ficticia. Tras actualizar el tablero, volver a abrir el botón para verificar que siga respondiendo. WhatsApp debe permanecer gris y deshabilitado. El nombre abre el expediente y Más conserva las acciones secundarias.
