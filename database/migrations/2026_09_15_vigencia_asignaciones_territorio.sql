-- La asignación territorial no maneja programación futura.
-- Regla global: activo = 1 significa asignación vigente desde hoy y sin fecha de fin.

-- Normaliza registros heredados que no cumplen la regla actual.
UPDATE asignaciones_territorio
SET activo = 0,
    updated_at = NOW()
WHERE activo = 1
  AND (
      (fecha_inicio IS NOT NULL AND fecha_inicio > CURDATE())
      OR fecha_fin IS NOT NULL
  );

DROP TRIGGER IF EXISTS trg_asignaciones_territorio_vigencia_insert;
DROP TRIGGER IF EXISTS trg_asignaciones_territorio_vigencia_update;

DELIMITER $$

CREATE TRIGGER trg_asignaciones_territorio_vigencia_insert
BEFORE INSERT ON asignaciones_territorio
FOR EACH ROW
BEGIN
    IF NEW.activo = 1
       AND NEW.fecha_inicio IS NOT NULL
       AND NEW.fecha_inicio > CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una asignación territorial activa no puede iniciar en una fecha futura.';
    END IF;

    IF NEW.activo = 1 AND NEW.fecha_fin IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una asignación territorial activa no puede tener fecha de finalización.';
    END IF;

    IF NEW.fecha_inicio IS NOT NULL
       AND NEW.fecha_fin IS NOT NULL
       AND NEW.fecha_fin < NEW.fecha_inicio THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La fecha de finalización no puede ser anterior al inicio.';
    END IF;
END$$

CREATE TRIGGER trg_asignaciones_territorio_vigencia_update
BEFORE UPDATE ON asignaciones_territorio
FOR EACH ROW
BEGIN
    IF NEW.activo = 1
       AND NEW.fecha_inicio IS NOT NULL
       AND NEW.fecha_inicio > CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una asignación territorial activa no puede iniciar en una fecha futura.';
    END IF;

    IF NEW.activo = 1 AND NEW.fecha_fin IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Una asignación territorial activa no puede tener fecha de finalización.';
    END IF;

    IF NEW.fecha_inicio IS NOT NULL
       AND NEW.fecha_fin IS NOT NULL
       AND NEW.fecha_fin < NEW.fecha_inicio THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La fecha de finalización no puede ser anterior al inicio.';
    END IF;
END$$

DELIMITER ;
