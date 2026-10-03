-- Normalización de números móviles mexicanos en WhatsApp.
-- Meta puede reportar remitentes históricos como 521 + 10 dígitos,
-- mientras que Cloud API utiliza 52 + 10 dígitos para el envío.
-- Esta migración fusiona conversaciones duplicadas y conserva sus mensajes.

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_whatsapp_mexico_duplicados;

CREATE TEMPORARY TABLE tmp_whatsapp_mexico_duplicados (
    conversacion_duplicada_id INT NOT NULL,
    conversacion_canonica_id INT NOT NULL,
    PRIMARY KEY (conversacion_duplicada_id),
    KEY idx_tmp_whatsapp_canonica (conversacion_canonica_id)
) ENGINE=MEMORY;

INSERT INTO tmp_whatsapp_mexico_duplicados (
    conversacion_duplicada_id,
    conversacion_canonica_id
)
SELECT
    duplicada.id,
    canonica.id
FROM whatsapp_conversaciones duplicada
INNER JOIN whatsapp_conversaciones canonica
    ON canonica.cuenta_id = duplicada.cuenta_id
   AND canonica.telefono_normalizado =
        CONCAT('52', SUBSTRING(duplicada.telefono_normalizado, 4))
WHERE duplicada.telefono_normalizado REGEXP '^521[0-9]{10}$'
  AND canonica.telefono_normalizado REGEXP '^52[0-9]{10}$';

-- Conserva en la conversación canónica los datos útiles de la duplicada.
UPDATE whatsapp_conversaciones canonica
INNER JOIN tmp_whatsapp_mexico_duplicados mapa
    ON mapa.conversacion_canonica_id = canonica.id
INNER JOIN whatsapp_conversaciones duplicada
    ON duplicada.id = mapa.conversacion_duplicada_id
SET
    canonica.nombre_contacto = COALESCE(
        NULLIF(canonica.nombre_contacto, ''),
        NULLIF(duplicada.nombre_contacto, '')
    ),
    canonica.aliado_seguimiento_id = COALESCE(
        canonica.aliado_seguimiento_id,
        duplicada.aliado_seguimiento_id
    ),
    canonica.responsable_usuario_id = COALESCE(
        canonica.responsable_usuario_id,
        duplicada.responsable_usuario_id
    ),
    canonica.estado = CASE
        WHEN canonica.estado = 'ABIERTA' OR duplicada.estado = 'ABIERTA'
            THEN 'ABIERTA'
        ELSE canonica.estado
    END,
    canonica.ventana_servicio_hasta = CASE
        WHEN canonica.ventana_servicio_hasta IS NULL
            THEN duplicada.ventana_servicio_hasta
        WHEN duplicada.ventana_servicio_hasta IS NULL
            THEN canonica.ventana_servicio_hasta
        ELSE GREATEST(
            canonica.ventana_servicio_hasta,
            duplicada.ventana_servicio_hasta
        )
    END,
    canonica.no_leidos =
        canonica.no_leidos + duplicada.no_leidos,
    canonica.ultimo_mensaje_preview = CASE
        WHEN canonica.ultimo_mensaje_at IS NULL
            THEN duplicada.ultimo_mensaje_preview
        WHEN duplicada.ultimo_mensaje_at IS NOT NULL
             AND duplicada.ultimo_mensaje_at > canonica.ultimo_mensaje_at
            THEN duplicada.ultimo_mensaje_preview
        ELSE canonica.ultimo_mensaje_preview
    END,
    canonica.ultimo_mensaje_at = CASE
        WHEN canonica.ultimo_mensaje_at IS NULL
            THEN duplicada.ultimo_mensaje_at
        WHEN duplicada.ultimo_mensaje_at IS NULL
            THEN canonica.ultimo_mensaje_at
        ELSE GREATEST(
            canonica.ultimo_mensaje_at,
            duplicada.ultimo_mensaje_at
        )
    END,
    canonica.updated_at = NOW();

-- Mueve todo el historial antes de eliminar la conversación duplicada.
UPDATE whatsapp_mensajes mensaje
INNER JOIN tmp_whatsapp_mexico_duplicados mapa
    ON mapa.conversacion_duplicada_id = mensaje.conversacion_id
SET mensaje.conversacion_id = mapa.conversacion_canonica_id;

DELETE duplicada
FROM whatsapp_conversaciones duplicada
INNER JOIN tmp_whatsapp_mexico_duplicados mapa
    ON mapa.conversacion_duplicada_id = duplicada.id;

-- Convierte conversaciones mexicanas antiguas que todavía no tenían
-- contraparte canónica.
UPDATE whatsapp_conversaciones
SET
    telefono_contacto =
        CONCAT('52', SUBSTRING(telefono_normalizado, 4)),
    telefono_normalizado =
        CONCAT('52', SUBSTRING(telefono_normalizado, 4)),
    updated_at = NOW()
WHERE telefono_normalizado REGEXP '^521[0-9]{10}$';

-- Mantiene también el teléfono visible en el mismo formato canónico.
UPDATE whatsapp_conversaciones
SET telefono_contacto = telefono_normalizado
WHERE telefono_normalizado REGEXP '^52[0-9]{10}$'
  AND telefono_contacto <> telefono_normalizado;

DROP TEMPORARY TABLE IF EXISTS tmp_whatsapp_mexico_duplicados;

COMMIT;
