<?php
    public function __construct(?mysqli $db=null){
        $this->db=$db??(new Database())->connect();
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_notificaciones (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,
            extension VARCHAR(12) NOT NULL,llave VARCHAR(150) NOT NULL,numero VARCHAR(40) NOT NULL,
            titulo VARCHAR(160) NOT NULL,mensaje VARCHAR(300) NOT NULL,created_at DATETIME NOT NULL,
            leida TINYINT NOT NULL DEFAULT 0,UNIQUE KEY uk_tel_aviso(usuario_id,extension,llave),
            KEY idx_tel_aviso(usuario_id,extension,leida)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }