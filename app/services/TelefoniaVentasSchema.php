<?php
declare(strict_types=1);

/** Migración aditiva explícita. Los controladores y webhooks nunca la ejecutan. */
final class TelefoniaVentasSchema
{
    public static function cambios(): array
    {
        return [
            'telefonia_ventas_devolucion_intentos' => "CREATE TABLE telefonia_ventas_devolucion_intentos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,solicitud_id BIGINT UNSIGNED NOT NULL,version INT NOT NULL,
                usuario_id INT NOT NULL,extension VARCHAR(12) NOT NULL,numero VARCHAR(40) NOT NULL,
                desde_evento_id BIGINT NOT NULL,pbx_call_id VARCHAR(100) NULL,estado VARCHAR(20) NOT NULL DEFAULT 'PREPARADO',
                created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_venta_devolucion_llamada(usuario_id,pbx_call_id,extension),
                KEY idx_venta_devolucion(solicitud_id,version,id),KEY idx_venta_intento(usuario_id,estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'telefonia_contactos_personales' => "CREATE TABLE telefonia_contactos_personales (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,nombre VARCHAR(90) NOT NULL,
                telefono VARCHAR(18) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_telefono_contacto_usuario(usuario_id,telefono),KEY idx_telefono_contactos_usuario(usuario_id,nombre)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'telefonia_ventas_llamadas'  => "CREATE TABLE telefonia_ventas_llamadas (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL, extension VARCHAR(12) NOT NULL,
                pbx_call_id VARCHAR(100) NOT NULL, direccion VARCHAR(10) NOT NULL,
                numero VARCHAR(40) NOT NULL, nombre VARCHAR(150) NOT NULL DEFAULT '',
                origen_extension VARCHAR(12) NOT NULL DEFAULT '', solicitud_id BIGINT UNSIGNED NULL,
                estado_id INT NULL, fecha DATETIME NOT NULL,
                UNIQUE KEY uk_venta_tramo(pbx_call_id,extension),
                KEY idx_venta_historial(usuario_id,fecha,id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];
    }

    public static function columnasResultado(): array
    {
        return ['observacion'=>"VARCHAR(1000) NOT NULL DEFAULT ''",
            'prospecto_nombre'=>"VARCHAR(150) NOT NULL DEFAULT ''",
            'contacto_id'=>'BIGINT UNSIGNED NULL', 'proxima_accion_at'=>'DATETIME NULL', 'recordatorio_atendido_at'=>'DATETIME NULL'];
    }

    public static function pendientes(mysqli $db): array
    {
        $todo=[];
        foreach (self::cambios() as $table=>$sql) {
            $s=$db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$table);$s->execute();
            if (!$s->get_result()->num_rows) $todo[]=$sql;$s->close();
        }
        $s=$db->query("SHOW TABLES LIKE 'telefonia_ventas_resultados'");
        if (!$s->num_rows) {
            $todo[]="CREATE TABLE telefonia_ventas_resultados (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,
                extension VARCHAR(20) NOT NULL,pbx_call_id VARCHAR(100) NOT NULL,
                resultado VARCHAR(36) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_ventas_llamada_extension(pbx_call_id,extension),
                KEY idx_ventas_usuario_fecha(usuario_id,updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            foreach(self::columnasResultado() as $col=>$definition)
                $todo[]="ALTER TABLE telefonia_ventas_resultados ADD COLUMN $col $definition";
        } else {
            $cols=array_column($db->query('SHOW COLUMNS FROM telefonia_ventas_resultados')->fetch_all(MYSQLI_ASSOC),'Field');
            foreach(self::columnasResultado() as $col=>$definition)
                if(!in_array($col,$cols,true))$todo[]="ALTER TABLE telefonia_ventas_resultados ADD COLUMN $col $definition";
        }
        $exists=$db->query("SHOW TABLES LIKE 'telefonia_contactos_personales'")->num_rows>0;
        $cols=$exists?array_column($db->query('SHOW COLUMNS FROM telefonia_contactos_personales')->fetch_all(MYSQLI_ASSOC),'Field'):[];
        foreach(['correo'=>"VARCHAR(180) NOT NULL DEFAULT ''",'observacion'=>"VARCHAR(500) NOT NULL DEFAULT ''",'proxima_accion_at'=>'DATETIME NULL'] as $col=>$definition)
            if(!in_array($col,$cols,true))$todo[]="ALTER TABLE telefonia_contactos_personales ADD COLUMN $col $definition";
        return $todo;
    }
}
