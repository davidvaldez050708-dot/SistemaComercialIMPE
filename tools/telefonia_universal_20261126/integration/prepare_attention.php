<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');
$c=json_decode(file_get_contents($lab.'/credentials.json'),true,32,JSON_THROW_ON_ERROR);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Only synthetic loopback fixture');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($c['host'],$c['root_user'],$c['root_password'],$c['database'],$c['port']);$db->set_charset('utf8mb4');
foreach(['schema_recepcion.sql','schema_atencion_analista.sql','schema_evidencias.sql'] as $file)$db->query(file_get_contents(dirname(__DIR__).'/'.$file));
$dump=file_get_contents(dirname(__DIR__,3).'/database/sistema_comercial_impe.sql');
// ONLY schema definitions for an explicit allowlist. Never import dump rows.
foreach(['interacciones_vinculacion','seguimientos_vinculacion'] as $table){
 if(!preg_match('/CREATE TABLE `'.preg_quote($table,'/').'` \(.*?;(?=\r?\n)/s',$dump,$match))throw new RuntimeException('Missing explicit fixture schema');
 $sql=str_replace('CREATE TABLE `','CREATE TABLE IF NOT EXISTS `',$match[0]);$db->query($sql);
}
foreach(['seguimientos_vinculacion','interacciones_vinculacion'] as $table){
 if($db->query("SHOW INDEX FROM $table WHERE Key_name='PRIMARY'")->num_rows===0){
  preg_match_all('/ALTER TABLE `'.$table.'`.*?;/s',$dump,$alters);
  foreach($alters[0] as $alter)if(strpos($alter,'ADD CONSTRAINT')===false)$db->query($alter);
 }
}
$columns=array_column($db->query('SHOW COLUMNS FROM telefonia_analista_webrtc_sesiones')->fetch_all(MYSQLI_ASSOC),'Field');
foreach(['sdk_dialogo_json'=>'TEXT NULL','predecesora_session_id'=>'VARCHAR(100) NULL','operacion_transferencia_id'=>'BIGINT UNSIGNED NULL'] as $name=>$type)
 if(!in_array($name,$columns,true))$db->query('ALTER TABLE telefonia_analista_webrtc_sesiones ADD COLUMN '.$name.' '.$type);
// Explicit synthetic commercial fixture. No schema is inferred from personal data.
$definitions=[
 "ALTER TABLE prospecto_embudo_subetapas ADD COLUMN IF NOT EXISTS actualizado_por INT, ADD COLUMN IF NOT EXISTS actualizado_at DATETIME, ADD COLUMN IF NOT EXISTS version INT DEFAULT 1",
 "CREATE TABLE IF NOT EXISTS prospecto_embudo_subetapas_historial(id INT AUTO_INCREMENT PRIMARY KEY,asignacion_id INT,etapa_en_evento VARCHAR(40),subetapa_anterior VARCHAR(40),subetapa_nueva VARCHAR(40),motivo VARCHAR(500),origen VARCHAR(40),usuario_id INT,creado_at DATETIME) ENGINE=InnoDB",
 "CREATE TABLE IF NOT EXISTS prospecto_embudo_actividad_v3(id INT AUTO_INCREMENT PRIMARY KEY,asignacion_id INT,usuario_id INT,etapa VARCHAR(40),accion VARCHAR(40),motivo VARCHAR(500),fecha_anterior DATETIME NULL,fecha_pendiente DATETIME NULL,tipo_actividad VARCHAR(40),detalle_actividad TEXT,completada_at DATETIME NULL,subetapa_anterior VARCHAR(40),subetapa_nueva VARCHAR(40),creado_at DATETIME,KEY idx_asignacion(asignacion_id,id)) ENGINE=InnoDB",

 "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS telefono VARCHAR(40) DEFAULT '', ADD COLUMN IF NOT EXISTS password VARCHAR(255) DEFAULT '', ADD COLUMN IF NOT EXISTS ultimo_acceso DATETIME NULL, ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP, ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, ADD COLUMN IF NOT EXISTS requiere_cambio_password TINYINT DEFAULT 0, ADD COLUMN IF NOT EXISTS password_temporal_expira DATETIME NULL",

 "ALTER TABLE prospectos_comerciales ADD COLUMN IF NOT EXISTS pais_nombre VARCHAR(80) DEFAULT 'México'",
 "ALTER TABLE prospecto_asignaciones ADD COLUMN IF NOT EXISTS asesor_id INT, ADD COLUMN IF NOT EXISTS vigente TINYINT DEFAULT 1",
 "CREATE TABLE IF NOT EXISTS prospecto_embudo_llamadas(id BIGINT AUTO_INCREMENT PRIMARY KEY,asignacion_id INT NOT NULL,usuario_id INT NOT NULL,extension VARCHAR(12) NOT NULL,pbx_call_id VARCHAR(100) NOT NULL,resultado VARCHAR(40),creado_at DATETIME,UNIQUE KEY uk_tramo(extension,pbx_call_id)) ENGINE=InnoDB",
 "CREATE TABLE IF NOT EXISTS prospecto_embudo_etapas(asignacion_id INT PRIMARY KEY,etapa VARCHAR(40),cambiado_por INT,actualizado_at DATETIME) ENGINE=InnoDB",
 "CREATE TABLE IF NOT EXISTS prospecto_embudo_agenda(asignacion_id INT PRIMARY KEY,proxima_accion_at DATETIME NULL,revision_fecha DATE NULL,actualizado_at DATETIME,actualizado_por INT) ENGINE=InnoDB",
 "CREATE TABLE IF NOT EXISTS prospecto_embudo_movimientos(id INT AUTO_INCREMENT PRIMARY KEY,asignacion_id INT,etapa_anterior VARCHAR(40),etapa_nueva VARCHAR(40),motivo VARCHAR(250),usuario_id INT,creado_at DATETIME) ENGINE=InnoDB",
 "CREATE TABLE IF NOT EXISTS municipios(id INT PRIMARY KEY,estado_id INT,nombre VARCHAR(100),estado TINYINT DEFAULT 1) ENGINE=InnoDB"
];
foreach($definitions as $sql)$db->query($sql);
if($db->query("SHOW INDEX FROM prospecto_embudo_subetapas WHERE Key_name='uk_fixture_assignment'")->num_rows===0)$db->query('ALTER TABLE prospecto_embudo_subetapas ADD UNIQUE KEY uk_fixture_assignment(asignacion_id)');
echo "ATTENTION_SCHEMA_OK: synthetic schemas only, no dump rows or production access.\n";
