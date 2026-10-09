-- Formulario de Registro · Segundo número de contacto
-- Aplicar en instalaciones donde formulario_registros ya existe.

ALTER TABLE formulario_registros
    ADD COLUMN IF NOT EXISTS movil_secundario VARCHAR(10) NULL AFTER movil;
