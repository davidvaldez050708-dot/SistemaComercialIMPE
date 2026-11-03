-- Fase 1. Aditiva. Ejecutar exclusivamente desde la herramienta CLI autorizada.
-- No modifica tablas de formularios, usuarios, telefonía ni catálogos existentes.
CREATE TABLE IF NOT EXISTS prospectos_comerciales (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 nombre VARCHAR(80) NULL,
 apellidos VARCHAR(120) NULL,
 nombre_completo VARCHAR(255) NOT NULL,
 telefono_original VARCHAR(64) NULL,
 telefono_normalizado VARCHAR(32) NULL,
 correo VARCHAR(190) NULL,
 estado_id INT NULL,
 municipio_id INT NULL,
 pais_nombre VARCHAR(120) NULL,
 estado_provincia VARCHAR(120) NULL,
 ciudad VARCHAR(120) NULL,
 integridad_estado VARCHAR(24) NOT NULL,
 verificacion_estado VARCHAR(24) NOT NULL DEFAULT 'SIN_VERIFICAR',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 KEY idx_pc_telefono (telefono_normalizado),
 KEY idx_pc_fecha (created_at,id),
 KEY idx_pc_correo (correo),
 KEY idx_pc_estado (estado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un evento raíz identifica la solicitud original. Las procedencias de aliados
-- son eventos hijos: no se cuentan dos veces como solicitudes recibidas.
CREATE TABLE IF NOT EXISTS prospecto_origenes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 prospecto_id BIGINT UNSIGNED NOT NULL,
 origen_padre_id BIGINT UNSIGNED NULL,
 tipo_origen VARCHAR(24) NOT NULL,
 sistema_origen VARCHAR(40) NOT NULL,
 tipo_registro VARCHAR(48) NOT NULL,
 identificador_origen VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
 registro_tabla VARCHAR(64) NULL,
 registro_id VARCHAR(190) COLLATE utf8mb4_bin NULL,
 aliado_id BIGINT UNSIGNED NULL,
 aliado_nombre VARCHAR(255) NULL,
 formulario_id VARCHAR(190) COLLATE utf8mb4_bin NULL,
 formulario_nombre VARCHAR(255) NULL,
 campania_id VARCHAR(190) COLLATE utf8mb4_bin NULL,
 campania_nombre VARCHAR(255) NULL,
 conjunto_id VARCHAR(190) COLLATE utf8mb4_bin NULL,
 conjunto_nombre VARCHAR(255) NULL,
 anuncio_id VARCHAR(190) COLLATE utf8mb4_bin NULL,
 anuncio_nombre VARCHAR(255) NULL,
 meta_lead_id VARCHAR(190) COLLATE utf8mb4_bin NULL,
 plataforma VARCHAR(40) NULL,
 fecha_registro_original DATETIME NULL,
 fecha_registro_texto VARCHAR(128) NULL,
 zona_horaria_origen VARCHAR(64) NULL,
 incorporado_at DATETIME NOT NULL,
 datos_originales_ref VARCHAR(255) NOT NULL,
 fuente_hash CHAR(64) NULL,
 lote_importacion_ref VARCHAR(190) COLLATE utf8mb4_bin NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_po_evento (sistema_origen,tipo_registro,identificador_origen),
 UNIQUE KEY uq_po_persona (id,prospecto_id),
 KEY idx_po_persona (prospecto_id),
 KEY idx_po_padre (origen_padre_id,prospecto_id),
 KEY idx_po_tipo_fecha (tipo_origen,fecha_registro_original),
 KEY idx_po_aliado (aliado_id),
 KEY idx_po_meta (meta_lead_id),
 CONSTRAINT fk_po_persona FOREIGN KEY (prospecto_id) REFERENCES prospectos_comerciales(id),
 CONSTRAINT fk_po_padre FOREIGN KEY (origen_padre_id,prospecto_id) REFERENCES prospecto_origenes(id,prospecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_intereses (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 prospecto_id BIGINT UNSIGNED NOT NULL,
 origen_id BIGINT UNSIGNED NOT NULL,
 programa_clave CHAR(64) NOT NULL,
 programa_codigo VARCHAR(80) NULL,
 nombre_original VARCHAR(255) NOT NULL,
 fecha_interes DATETIME NULL,
 clasificacion_estado VARCHAR(24) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_pi_origen_programa (origen_id,programa_clave),
 KEY idx_pi_persona (prospecto_id),
 KEY idx_pi_origen_persona (origen_id,prospecto_id),
 KEY idx_pi_programa (programa_codigo),
 CONSTRAINT fk_pi_origen FOREIGN KEY (origen_id,prospecto_id) REFERENCES prospecto_origenes(id,prospecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_preferencias_contacto (
 origen_id BIGINT UNSIGNED NOT NULL,
 valor_original TEXT NULL,
 dias_semana VARCHAR(32) NULL,
 hora_desde TIME NULL,
 hora_hasta TIME NULL,
 zona_horaria VARCHAR(64) NULL,
 interpretacion_estado VARCHAR(32) NOT NULL,
 PRIMARY KEY (origen_id),
 CONSTRAINT fk_pp_origen FOREIGN KEY (origen_id) REFERENCES prospecto_origenes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservas para fases posteriores: sin procesos de asignación activos.
CREATE TABLE IF NOT EXISTS prospecto_asignaciones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 prospecto_id BIGINT UNSIGNED NOT NULL,
 asesor_id INT NOT NULL,
 asignado_por INT NULL,
 asignado_at DATETIME NOT NULL,
 fecha_trabajo DATE NULL,
 proxima_accion_at DATETIME NULL,
 finalizado_at DATETIME NULL,
 vigente TINYINT UNSIGNED NULL,
 estado VARCHAR(24) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_pa_vigente (prospecto_id,vigente),
 KEY idx_pa_asesor_fecha (asesor_id,fecha_trabajo),
 CONSTRAINT fk_pa_persona FOREIGN KEY (prospecto_id) REFERENCES prospectos_comerciales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospecto_integraciones (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 prospecto_id BIGINT UNSIGNED NOT NULL,
 sistema VARCHAR(32) NOT NULL,
 externo_id VARCHAR(190) COLLATE utf8mb4_bin NULL,
 modulo_destino VARCHAR(100) NULL,
 referencia_estable VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
 estado VARCHAR(24) NOT NULL DEFAULT 'NO_CONFIGURADO',
 ultimo_intento_at DATETIME NULL,
 sincronizado_at DATETIME NULL,
 ultimo_error_codigo VARCHAR(100) NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_px_sistema (prospecto_id,sistema),
 UNIQUE KEY uq_px_referencia (sistema,referencia_estable),
 CONSTRAINT fk_px_persona FOREIGN KEY (prospecto_id) REFERENCES prospectos_comerciales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
