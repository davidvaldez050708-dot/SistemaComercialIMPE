<?php
// Disposable database created by whatsapp_ventas_db.php.
if(getenv('IMPE_WHATSAPP_VENTAS_TEST')!=='YES' || !preg_match('/^impe_wa_ventas_test_[a-f0-9]{10}$/D',$schema))exit(2);
$db->query("CREATE TABLE whatsapp_asignaciones_historial(id BIGINT AUTO_INCREMENT PRIMARY KEY,conversacion_id INT NOT NULL,responsable_anterior_id INT NULL,responsable_nuevo_id INT NOT NULL,actor_usuario_id INT NULL,origen VARCHAR(20),created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$db->query("INSERT IGNORE INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo IN ('whatsapp.ver','whatsapp.enviar')");
$db->query("INSERT IGNORE INTO rol_permisos SELECT 4,id FROM permisos WHERE codigo IN ('whatsapp.ver','whatsapp.enviar')");
$db->query("INSERT IGNORE INTO rol_permisos SELECT 2,id FROM permisos WHERE codigo IN ('whatsapp.ver','whatsapp.enviar')");
