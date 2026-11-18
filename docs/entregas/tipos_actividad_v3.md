# Próximas actividades comerciales con tipo y detalle

Base: `861794518981d034df4d6fa86cf815f427fcb377` (acciones contextuales ya aplicadas).

Al programar se exige elegir Llamar, Enviar mensaje o información, Revisar documentos,
Revisar pago o comprobante, Revisar solicitud de inscripción u Otra gestión. El tipo
no acredita una conversación, pago, documento o inscripción y no envía comunicaciones.
WhatsApp sigue reservado.

El tipo y el detalle original de la tarea se guardan separados del motivo de cada
evento. Registrar una nota conserva la tarea pendiente. Reprogramar conserva su tipo
y detalle, precarga la fecha y exige una fecha diferente. Completar retira la fecha
pendiente y conserva la tarea y el resultado en el expediente. Una nueva programación
después de completar exige elegir nuevamente el tipo. Las etapas mantienen las reglas
existentes: Nuevos sin conversación y trámites de inscripción conservan su etapa;
Contactados y Potenciales pasan a Seguimiento al programar.

Los mismos tipos se usan al programar desde Gestionar avance y desde el movimiento
manual permitido a Seguimiento. La próxima llamada capturada en un resultado PBX se
guarda como LLAMADA, sin modificar el servicio protegido de Telefonía. Tarjetas,
expediente, resumen y API de historial consultan el mismo evento vigente.

Las actividades anteriores quedan con tipo GESTION y detalle NULL. No se deduce su
tipo a partir del texto ni se reescribe su historial. La campana y las anticipaciones
son la siguiente fase; esta entrega prepara datos estructurados y no las habilita.

## Comprobaciones realizadas

- MariaDB aislada, datos sintéticos: seis tipos; seis etapas; nota, reprogramación y
  cumplimiento conservando tipo/detalle; tipo vacío/desconocido rechazado sin cambios;
  versión obsoleta, concurrencia, permisos, reversión transaccional, programación
  desde avance comercial, llamada PBX y movimiento manual.
- Plantillas reales: datos tipificados en asesor/supervisión, expediente y API;
  escape HTML, navegación y autorización. Las consultas siguen sin escrituras.
- Navegador: selección obligatoria, programación, reprogramación precargada,
  detalle original visible, avance con fecha, refresco parcial sin pantalla blanca,
  eventos únicos, doce combinaciones de etapa/operación y modal móvil de 390 px.
- Publicador: bases y hashes exactos, bloqueo ante personalizaciones, respaldo,
  fallo parcial y rollback. Entrega completa ejecutada contra base desechable:
  CHECK sin escrituras, ALTER instantáneo, APPLY idempotente, datos históricos
  intactos y ROLLBACK conservando tipos posteriores a la publicación.

## Publicación y reversión

El publicador verifica bases exactas y dependencias, incluida la variante revisada
de Telefonía de Hostinger. CHECK sólo consulta metadatos y valida archivos. APPLY
crea y verifica el respaldo de código antes de ampliar el esquema y publicar.

La ampliación es aditiva: `tipo_actividad VARCHAR(32) NOT NULL DEFAULT 'GESTION'`
y `detalle_actividad VARCHAR(190) NULL` en `prospecto_embudo_actividad_v3`. Usa
`ALGORITHM=INSTANT, LOCK=NONE`, con espera de bloqueo de metadatos limitada a cinco
segundos. Si no es compatible, se bloquea sin usar una reconstrucción alternativa.
No hace UPDATE de actividades históricas. No modifica resultados, asignaciones,
permisos, respaldos R16 ni integraciones de WhatsApp.

Con el paquete extraído en una carpeta privada:

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PUBLICADOR="$ENTREGA/tools/publicar_tipos_actividad_v3_hostinger.py"
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" \
  --backup-root "$HOME/respaldos-tipos-actividad-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

Antes de aplicar puede aparecer ESQUEMA_TIPOS_PENDIENTE. Al terminar deben aparecer
ESQUEMA_TIPOS_OK, APPLY_OK y CHECK_OK. El motor compartido conserva los identificadores
de confirmación anteriores; no forzar hashes si hay bloqueo por personalización.

Para revertir el código, usar el mismo paquete y el ROLLBACK_BACKUP exacto:

```bash
python3 "$PUBLICADOR" --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE
```

La reversión conserva las columnas aditivas y los datos nuevos, compatibles con el
código anterior. No ejecutar DROP COLUMN. Las escrituras realizadas posteriormente
con una versión antigua no conservan los metadatos nuevos; el rollback es de código,
no un mecanismo para seguir utilizando los tipos con la versión anterior.

Después de publicar, cargar una vez con Ctrl+F5. Primera prueba con una tarjeta
ficticia: programar Revisar documentos, indicar detalle y fecha futura; verificar
tarjeta y expediente; registrar una nota y comprobar que la tarea original persiste.
Después reprogramar y completar, comprobando fechas, historial y ausencia de un
pendiente residual. No repetir llamadas reales para verificar únicamente el modal.
