-- Flujo manual asistido de WhatsApp para Cuenta Clave.
-- Conserva la integración Cloud API para pruebas/administración, pero el flujo
-- operativo de Aliados prepara el contenido para compartirlo fuera del CRM.

ALTER TABLE convocatorias
    ADD COLUMN IF NOT EXISTS enlace_registro VARCHAR(1000) NULL AFTER imagen;

ALTER TABLE aliados_convocatorias_envios
    ADD COLUMN IF NOT EXISTS convocatoria_enlace_registro VARCHAR(1000) NULL
    AFTER convocatoria_imagen;

INSERT INTO permisos (modulo, codigo, nombre, descripcion, estado)
VALUES (
    'Aliados',
    'aliados.preparar_whatsapp',
    'Preparar convocatorias para WhatsApp',
    'Preparar número, mensaje, enlace e imagen de una convocatoria para compartirla manualmente por WhatsApp.',
    1
)
ON DUPLICATE KEY UPDATE
    modulo = VALUES(modulo),
    nombre = VALUES(nombre),
    descripcion = VALUES(descripcion),
    estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permisos p
    ON p.codigo = 'aliados.preparar_whatsapp'
WHERE r.nombre = 'Cuenta Clave'
  AND p.estado = 1;

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT 1, p.id
FROM permisos p
WHERE p.codigo = 'aliados.preparar_whatsapp'
  AND p.estado = 1;

-- Mientras el canal API permanezca en fase de pruebas, Cuenta Clave no verá
-- ni enviará desde el módulo WhatsApp Business. El administrador puede
-- reactivar estos permisos posteriormente desde Roles y Permisos.
DELETE rp
FROM rol_permisos rp
INNER JOIN roles r
    ON r.id = rp.rol_id
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE r.nombre = 'Cuenta Clave'
  AND p.codigo IN ('whatsapp.ver', 'whatsapp.enviar');
