CREATE TABLE IF NOT EXISTS whatsapp_ventas_config (
    cuenta_id INT NOT NULL PRIMARY KEY,
    messaging_account_id VARCHAR(80) NOT NULL,
    plantilla_nombre VARCHAR(512) NOT NULL,
    plantilla_idioma VARCHAR(20) NOT NULL,
    plantilla_cuerpo TEXT NOT NULL,
    plantilla_categoria VARCHAR(30) NOT NULL,
    verificada_at DATETIME NOT NULL,
    actualizada_por INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
