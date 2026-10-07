-- Previsualización segura antes de limpiar los seguimientos de prueba
-- de Diego Bahena en Ciudad de México.
-- Este script NO modifica datos.

SET @analista_id := (
    SELECT u.id
    FROM usuarios u
    WHERE u.estado = 1
      AND u.rol_id = 4
      AND LOWER(TRIM(u.nombre)) = 'diego'
      AND LOWER(TRIM(u.apellidos)) = 'bahena'
    ORDER BY u.id
    LIMIT 1
);

SET @estado_id := (
    SELECT e.id
    FROM estados e
    WHERE e.estado = 1
      AND (
          e.clave_inegi = '09'
          OR LOWER(TRIM(e.nombre)) IN (
              'ciudad de méxico',
              'ciudad de mexico',
              'cdmx'
          )
      )
    ORDER BY
        CASE WHEN e.clave_inegi = '09' THEN 0 ELSE 1 END,
        e.id
    LIMIT 1
);

SELECT
    @analista_id AS analista_id,
    (
        SELECT CONCAT_WS(' ', u.nombre, u.apellidos)
        FROM usuarios u
        WHERE u.id = @analista_id
    ) AS analista,
    @estado_id AS estado_id,
    (
        SELECT e.nombre
        FROM estados e
        WHERE e.id = @estado_id
    ) AS estado;

SELECT
    COUNT(*) AS seguimientos_prueba_a_eliminar
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id;

SELECT
    s.estado_seguimiento,
    COUNT(*) AS total
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
GROUP BY s.estado_seguimiento
ORDER BY total DESC, s.estado_seguimiento;

SELECT
    s.id,
    s.nombre_entidad,
    s.tipo_entidad,
    s.origen,
    s.clave_origen,
    s.estado_seguimiento,
    s.telefono_fuente,
    s.correo_fuente,
    s.fecha_inicio,
    s.ultima_interaccion_at
FROM seguimientos_vinculacion s
WHERE s.analista_id = @analista_id
  AND s.estado_id = @estado_id
ORDER BY s.id;
