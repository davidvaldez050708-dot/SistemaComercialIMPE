-- Aditiva e idempotente. Requiere 2026_10_19_formulario_aliado_programas.sql.
-- No modifica tokens, autorizaciones ni respuestas de enlaces anteriores.
CREATE TABLE IF NOT EXISTS aliados_manuales (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 estado_id INT NOT NULL,
 municipio_id INT NULL,
 nombre_entidad VARCHAR(180) NOT NULL,
 nombre_normalizado VARCHAR(180) NOT NULL,
 tipo_entidad VARCHAR(30) NOT NULL,
 contacto_nombre VARCHAR(120) NOT NULL DEFAULT '',
 contacto_cargo VARCHAR(120) NOT NULL DEFAULT '',
 correo_contacto VARCHAR(180) NOT NULL DEFAULT '',
 telefono_fuente VARCHAR(30) NOT NULL DEFAULT '',
 relacion_fecha DATE NULL,
 referencia VARCHAR(180) NOT NULL DEFAULT '',
 notas VARCHAR(1500) NOT NULL DEFAULT '',
 situacion ENUM('VIGENTE','HISTORICA') NOT NULL DEFAULT 'VIGENTE',
 activo TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 actualizado_por INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY identidad_territorial (estado_id, nombre_normalizado, municipio_id),
 KEY directorio_estado (estado_id, activo),
 FOREIGN KEY (estado_id) REFERENCES estados(id),
 FOREIGN KEY (municipio_id) REFERENCES municipios(id),
 FOREIGN KEY (creado_por) REFERENCES usuarios(id),
 FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perfil exclusivo de Registro; no reutiliza tokens de Inscripción ni Handly.
CREATE TABLE IF NOT EXISTS formulario_aliado_registro (
 aliado_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 manual_id BIGINT UNSIGNED NULL UNIQUE,
 seguimiento_id INT NULL UNIQUE,
 habilitado TINYINT NOT NULL DEFAULT 1,
 publicado TINYINT NOT NULL DEFAULT 0,
 actualizado_por INT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (aliado_id) REFERENCES formulario_aliados(id),
 FOREIGN KEY (manual_id) REFERENCES aliados_manuales(id),
 FOREIGN KEY (seguimiento_id) REFERENCES seguimientos_vinculacion(id),
 FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos exactos de opciones generales existentes; no se equipara Bachillerato
-- General de Handly con Bachillerato General ni se renombra catálogo histórico.
INSERT INTO formulario_programas (codigo,nombre)
SELECT 'bachillerato-2-anos','Bachillerato en 2 años' WHERE NOT EXISTS (SELECT 1 FROM formulario_programas WHERE codigo='bachillerato-2-anos' OR nombre='Bachillerato en 2 años');
INSERT INTO formulario_programas (codigo,nombre)
SELECT 'bachillerato-general','Bachillerato General' WHERE NOT EXISTS (SELECT 1 FROM formulario_programas WHERE codigo='bachillerato-general' OR nombre='Bachillerato General');
INSERT INTO formulario_programas (codigo,nombre)
SELECT 'ingles','Inglés' WHERE NOT EXISTS (SELECT 1 FROM formulario_programas WHERE codigo='ingles' OR nombre='Inglés');
INSERT INTO formulario_programas (codigo,nombre)
SELECT 'ejecutivas','Titulación - Ejecutivas' WHERE NOT EXISTS (SELECT 1 FROM formulario_programas WHERE codigo='ejecutivas' OR nombre='Titulación - Ejecutivas');
INSERT INTO formulario_programas (codigo,nombre)
SELECT 'titulacion-experiencia-laboral','Titulación por experiencia laboral' WHERE NOT EXISTS (SELECT 1 FROM formulario_programas WHERE codigo='titulacion-experiencia-laboral' OR nombre='Titulación por experiencia laboral');
INSERT INTO formulario_programas (codigo,nombre)
SELECT 'inscripciones-abiertas','Inscripciones Abiertas' WHERE NOT EXISTS (SELECT 1 FROM formulario_programas WHERE codigo='inscripciones-abiertas' OR nombre='Inscripciones Abiertas');

INSERT IGNORE INTO permisos (modulo,codigo,nombre,descripcion,estado) VALUES
('Aliados','aliados.crear_manual','Agregar aliado manual','Alta territorial sin crear expediente.',1),
('Aliados','aliados.editar_manual','Editar aliado manual','Edición y baja lógica en territorio autorizado.',1),
('Aliados','aliados.gestionar_programas','Programas de aliados','Seleccionar programas de Registro en territorio autorizado.',1),
('Formularios','formularios_aliados.gestionar_enlaces','Enlaces de Registro por aliado','Marketing gestiona captura exclusiva de Registro.',1);
INSERT IGNORE INTO rol_permisos (rol_id,permiso_id)
SELECT r.id,p.id FROM roles r JOIN permisos p ON p.codigo IN ('aliados.crear_manual','aliados.editar_manual','aliados.gestionar_programas') WHERE r.nombre='Cuenta Clave';
INSERT IGNORE INTO rol_permisos (rol_id,permiso_id)
SELECT r.id,p.id FROM roles r JOIN permisos p ON p.codigo='formularios_aliados.gestionar_enlaces' WHERE r.nombre='Marketing';
