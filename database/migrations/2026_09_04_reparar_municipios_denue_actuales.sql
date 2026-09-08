-- Corrige los seguimientos DENUE existentes detectados en las bases de desarrollo.
-- Solo actualiza registros que todavía tienen municipio_id en NULL.

UPDATE seguimientos_vinculacion s
INNER JOIN municipios m
    ON m.estado_id = s.estado_id
    AND m.nombre = 'León'
    AND m.estado = 1
SET s.municipio_id = m.id,
    s.updated_at = NOW()
WHERE s.municipio_id IS NULL
  AND s.origen = 'DENUE'
  AND s.clave_origen IN (
      'DENUE:6879422', -- DIVISION CORPORATIVA FLEXI
      'DENUE:6261551', -- IBERO LEON
      'DENUE:1269270'  -- INEGI León
  );

UPDATE seguimientos_vinculacion s
INNER JOIN municipios m
    ON m.estado_id = s.estado_id
    AND m.nombre = 'Celaya'
    AND m.estado = 1
SET s.municipio_id = m.id,
    s.updated_at = NOW()
WHERE s.municipio_id IS NULL
  AND s.origen = 'DENUE'
  AND s.clave_origen IN (
      'DENUE:6871611', -- ITESBA
      'DENUE:6329740'  -- ITESBA
  );

UPDATE seguimientos_vinculacion s
INNER JOIN municipios m
    ON m.estado_id = s.estado_id
    AND m.nombre = 'Salamanca'
    AND m.estado = 1
SET s.municipio_id = m.id,
    s.updated_at = NOW()
WHERE s.municipio_id IS NULL
  AND s.origen = 'DENUE'
  AND s.clave_origen = 'DENUE:1119695'; -- Colegio Particular Benjamin Franklin

UPDATE seguimientos_vinculacion s
INNER JOIN municipios m
    ON m.estado_id = s.estado_id
    AND m.nombre = 'Dolores Hidalgo Cuna de la Independencia Nacional'
    AND m.estado = 1
SET s.municipio_id = m.id,
    s.updated_at = NOW()
WHERE s.municipio_id IS NULL
  AND s.origen = 'DENUE'
  AND s.clave_origen = 'DENUE:1195687'; -- Presidencia Municipal
