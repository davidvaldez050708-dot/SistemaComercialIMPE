<?php
// Sólo datos sintéticos: no es una migración ni se incluye en la publicación.
if (PHP_SAPI!=='cli' || !preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:'')) exit(2);
require_once __DIR__.'/../config/db_connection.php';
$db=(new Database())->connect(false);
$db->query("CREATE TABLE aliados_contactos (id INT AUTO_INCREMENT PRIMARY KEY,seguimiento_id INT,numero VARCHAR(40),numero_normalizado VARCHAR(40),etiqueta VARCHAR(80),origen VARCHAR(40),confirmado_whatsapp TINYINT DEFAULT 0,autorizado_whatsapp TINYINT DEFAULT 0,autorizado_whatsapp_at DATETIME NULL,autorizado_whatsapp_por INT NULL,preferido_difusion TINYINT DEFAULT 0,activo TINYINT DEFAULT 1,creado_por INT,actualizado_por INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY contacto_unico (seguimiento_id,numero_normalizado)) ENGINE=InnoDB");
$db->query("UPDATE seguimientos_vinculacion SET telefono_fuente='7773456789' WHERE id=10");
$db->query("INSERT IGNORE INTO permisos (modulo,codigo,nombre,estado) VALUES ('aliados','aliados.ver','Consultar aliados',1),('aliados','aliados.gestionar_contactos','Gestionar canales',1)");
$db->query("INSERT IGNORE INTO rol_permisos SELECT 4,id FROM permisos WHERE codigo IN ('aliados.ver','aliados.gestionar_contactos')");
echo "OK: fixture de contactos institucionales para comparar ambos modales\n";
