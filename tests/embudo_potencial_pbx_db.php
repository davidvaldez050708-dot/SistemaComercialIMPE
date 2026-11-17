<?php
/** Ejecutar únicamente contra una MariaDB desechable de loopback. */
if (PHP_SAPI !== 'cli' || getenv('IMPE_POTENCIAL_PBX_TEST') !== 'YES') exit(2);
require __DIR__.'/../app/services/EmbudoComercialResultadoBridge.php';
require __DIR__.'/../app/services/EmbudoComercialV3MovimientosPermitidos.php';

function comprobarPotencial(bool $ok, string $caso): void
{
    if (!$ok) throw new RuntimeException($caso);
}

foreach ([
    ['NUEVO','PROSPECTO_POTENCIAL','CONTACTADO'],
    ['CONTACTADO','PROSPECTO_POTENCIAL','POTENCIAL'],
    ['CONTACTADO','CONVERSACION_PERSONA',null],
    ['CONTACTADO','SOLICITA_INFORMACION',null],
    ['CONTACTADO','SIN_RESPUESTA',null],
    ['CONTACTADO','INFORMACION_WHATSAPP',null],
    ['SEGUIMIENTO','PROSPECTO_POTENCIAL',null],
    ['POTENCIAL','PROSPECTO_POTENCIAL',null],
    ['PROCESO_INSCRIPCION','PROSPECTO_POTENCIAL',null],
    ['RECUPERACION','PROSPECTO_POTENCIAL',null],
    ['DESCARTADO','PROSPECTO_POTENCIAL',null],
] as [$actual,$resultado,$esperado]) {
    comprobarPotencial(EmbudoComercialV3MovimientosPermitidos::destinoPorConversacion($actual,$resultado)===$esperado,
        "$actual / $resultado");
}

mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'',(int)getenv('MYSQL_PORT'));
$db->query("CREATE TEMPORARY TABLE mysql.prospecto_asignaciones(id INT PRIMARY KEY, asesor_id INT, vigente INT, prospecto_id INT)");
$db->select_db('mysql');
foreach ([
    'prospectos_comerciales(id INT PRIMARY KEY,telefono_normalizado VARCHAR(20),pais_nombre VARCHAR(30))',
    'prospecto_embudo_etapas(asignacion_id INT PRIMARY KEY,etapa VARCHAR(40),cambiado_por INT,actualizado_at DATETIME)',
    'prospecto_embudo_llamadas(asignacion_id INT,usuario_id INT,extension VARCHAR(10),pbx_call_id VARCHAR(100) PRIMARY KEY,resultado VARCHAR(40),creado_at DATETIME)',
    'prospecto_embudo_movimientos(asignacion_id INT,etapa_anterior VARCHAR(40),etapa_nueva VARCHAR(40),motivo VARCHAR(190),usuario_id INT,creado_at DATETIME)',
    'prospecto_embudo_agenda(asignacion_id INT PRIMARY KEY,proxima_accion_at DATETIME)',
] as $tabla) {
    $db->query('CREATE TEMPORARY TABLE '.$tabla.' ENGINE=InnoDB');
}
$db->query("INSERT INTO prospecto_asignaciones VALUES(1,22,1,1),(2,22,1,1)");
$db->query("INSERT INTO prospectos_comerciales VALUES(1,'+525500000001','Mexico')");
$db->query("INSERT INTO prospecto_embudo_etapas VALUES(1,'CONTACTADO',22,NOW()),(2,'CONTACTADO',22,NOW())");
$db->query("INSERT INTO prospecto_embudo_agenda VALUES(1,'2030-01-01 10:30:00')");
putenv('IMPE_VENTAS_V3_UI=PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111');
putenv('IMPE_VENTAS_V3_UI_ASESORES=22');
$_SESSION=['usuario_id'=>22,'rol_id'=>3,'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']];
$bridge=new EmbudoComercialResultadoBridge($db);
$pbx=['extension'=>'157','pbx_call_id'=>'out_prueba01','direccion'=>'Saliente','finalizada'=>true,
    'conectada'=>true,'numero'=>'5500000001'];
$db->begin_transaction();
$cambio=$bridge->aplicar(22,1,$pbx,'PROSPECTO_POTENCIAL',null);
$db->commit();
comprobarPotencial($cambio['movida'] && $cambio['etapa']==='POTENCIAL','avance desde el puente PBX');
comprobarPotencial($db->query("SELECT COUNT(*) FROM prospecto_embudo_movimientos WHERE asignacion_id=1 AND etapa_anterior='CONTACTADO' AND etapa_nueva='POTENCIAL'")->fetch_row()[0]==1,'historial');
comprobarPotencial($db->query('SELECT proxima_accion_at FROM prospecto_embudo_agenda WHERE asignacion_id=1')->fetch_row()[0]==='2030-01-01 10:30:00','agenda');
$db->begin_transaction();
try {
    $bridge->aplicar(22,1,$pbx,'PROSPECTO_POTENCIAL',null);
    throw new RuntimeException('se aceptó un tramo PBX duplicado');
} catch (DomainException $esperado) {
    $db->rollback();
}
$_SESSION['permisos']=['ventas.embudo.ver'];
$pbx['pbx_call_id']='out_prueba02';
$db->begin_transaction();
$cambio=$bridge->aplicar(22,2,$pbx,'PROSPECTO_POTENCIAL',null);
$db->commit();
comprobarPotencial(!$cambio['movida'] && $cambio['etapa']==='CONTACTADO','permiso para mover');
echo "OK matriz V3, puente PBX, historial, agenda, duplicado y permisos\n";
