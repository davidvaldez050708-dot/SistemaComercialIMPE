-- Permiso de SOLO LECTURA para auditar las importaciones de Meta.
-- El Administrador puede consultarlas sin importar archivos.
-- Conserva todos los permisos existentes, las revocaciones históricas y los
-- datos de Meta; no habilita importación, distribución ni telefonía.
--
-- Una única concesión inicial a roles que YA contaban con consulta total
-- Y operación de importación O configuración de formularios, incluido el
-- Administrador. La migración puede repetirse sin restaurar revocaciones.
INSERT IGNORE INTO permisos
    (modulo,codigo,nombre,descripcion,estado)
VALUES
    ('Prospectos',
     'prospectos.ver_historial_meta',
     'Consultar historial de importaciones de Meta',
     'Consultar únicamente la bitácora de archivos de Meta y sus resultados. Requiere ver todos los prospectos; no permite importar ni modificar.',
     1);

SET @impe_historial_meta_permiso_creado := ROW_COUNT();

INSERT IGNORE INTO rol_permisos (rol_id,permiso_id)
SELECT DISTINCT rp_ver.rol_id, p_historial.id
FROM rol_permisos rp_ver
INNER JOIN permisos p_ver
    ON p_ver.id=rp_ver.permiso_id
    AND p_ver.codigo='prospectos.ver_todos'
    AND p_ver.estado=1
INNER JOIN rol_permisos rp_gestion
    ON rp_gestion.rol_id=rp_ver.rol_id
INNER JOIN permisos p_gestion
    ON p_gestion.id=rp_gestion.permiso_id
    AND p_gestion.estado=1
    AND p_gestion.codigo IN (
        'prospectos.importar_meta',
        'prospectos.configurar_formularios'
    )
INNER JOIN permisos p_historial
    ON p_historial.codigo='prospectos.ver_historial_meta'
    AND p_historial.estado=1
WHERE @impe_historial_meta_permiso_creado=1;

SET @impe_historial_meta_permiso_creado := NULL;
