-- Perfil educativo prioritario de adultos de 25 a 49 años.
-- Se mantiene separado de ITER porque el cruce edad × escolaridad proviene del
-- tabulado predefinido B2020_07_08_M del Censo 2020.
--
-- NO debe estimarse a partir de P18YM_PB ni de porcentajes generales: esta tabla
-- sólo admite cifras obtenidas del cruce oficial por grupo de edad.

CREATE TABLE IF NOT EXISTS perfil_educativo_prioritario (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estado_id INT NULL,
    clave_estado CHAR(2) NOT NULL,
    clave_municipio CHAR(3) NOT NULL DEFAULT '000',
    nombre_geografia VARCHAR(180) NULL,
    grupo_edad VARCHAR(12) NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL DEFAULT 2020,
    poblacion_total BIGINT UNSIGNED NOT NULL,
    sin_media_superior_concluida BIGINT UNSIGNED NULL,
    sin_superior BIGINT UNSIGNED NULL,
    fuente VARCHAR(255) NOT NULL,
    referencia_fuente VARCHAR(120) NOT NULL DEFAULT 'B2020_07_08_M',
    archivo_origen VARCHAR(255) NULL,
    fecha_consulta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_perfil_edu_geo_grupo (
        clave_estado,
        clave_municipio,
        grupo_edad,
        anio
    ),
    KEY idx_perfil_edu_estado (estado_id, anio),
    KEY idx_perfil_edu_geo (clave_estado, clave_municipio, anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
