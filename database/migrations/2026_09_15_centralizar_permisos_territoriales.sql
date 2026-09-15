-- La edición de investigación territorial vive únicamente en Información territorial.
-- Territorios conserva consulta y gestión de asignaciones del equipo.
UPDATE permisos
SET estado = 0,
    updated_at = NOW()
WHERE codigo IN (
    'territorios.editar',
    'territorios.actualizar_ficha'
);
