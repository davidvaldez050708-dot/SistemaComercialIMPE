<?php
require_once __DIR__.'/../app/services/ProspectoDistribucionReglas.php';
$checks=0;
$assert=static function($condition,$message)use(&$checks) {
    if (!$condition)throw new RuntimeException('FALLO: '.$message);
    $checks++;
};
$tz=new DateTimeZone('America/Mexico_City');
$ahora=new DateTimeImmutable('2026-11-06 12:00:00',$tz); // viernes
$assert(ProspectoDistribucionReglas::fechaSiguiente($ahora)==='2026-11-07','Meta jornada siguiente');
$assert(ProspectoDistribucionReglas::META_POR_ASESOR_DIA===80,'Cupo Meta fijo');
$assert(ProspectoDistribucionReglas::zonaContacto('México','Puebla',null)==='America/Mexico_City','zona Puebla');
$assert(ProspectoDistribucionReglas::zonaContacto('México','Morelos',null)==='America/Mexico_City','zona Morelos');
$assert(ProspectoDistribucionReglas::zonaContacto('México','Baja California',null)===null,'Baja California pendiente');
$assert(ProspectoDistribucionReglas::zonaContacto('Estados Unidos','California',null)===null,'zona EEUU pendiente');
$assert(ProspectoDistribucionReglas::zonaContacto('México',null,null)===null,'sin estado pendiente');
$pref=ProspectoDistribucionReglas::preferencia([
    'interpretacion_estado'=>'DEFINIDO_ZONA_PENDIENTE',
    'dias_semana'=>'[5]','hora_desde'=>'17:00:00','hora_hasta'=>'19:00:00',
    'pais_nombre'=>'México','estado_provincia'=>'Puebla'
]);
$assert($pref['tipo']==='DEFINIDA' && $pref['dias']===[5],'preferencia válida');
$turno=ProspectoDistribucionReglas::turno([
    5=>['desde'=>'16:00','hasta'=>'21:00']
],$pref,$ahora);
$assert($turno!==null && $turno['fecha_jornada']==='2026-11-06' &&
    $turno['proxima_accion_utc']==='2026-11-06 23:00:00','Aliado 17 a 19 con asesor 16 a 21');
$assert(ProspectoDistribucionReglas::turno(
    [5=>['desde'=>'08:00','hasta'=>'14:00']],$pref,$ahora
)===null,'incompatible por hora');
$assert(ProspectoDistribucionReglas::turno(
    [1=>['desde'=>'16:00','hasta'=>'21:00']],$pref,$ahora
)===null,'incompatible por día');
$assert(ProspectoDistribucionReglas::preferencia([
    'interpretacion_estado'=>'DEFINIDO_ZONA_PENDIENTE','dias_semana'=>'[5]',
    'hora_desde'=>'17:00','hora_hasta'=>'19:00','pais_nombre'=>'México',
    'estado_provincia'=>'Baja California'
])['motivo']==='ZONA_HORARIA_PENDIENTE','bloqueo de zona incierta');
$assert(ProspectoDistribucionReglas::preferencia([
    'interpretacion_estado'=>'NO_INTERPRETADO'
])['motivo']==='HORARIO_NO_INTERPRETADO','bloqueo de texto inválido');
$libre=ProspectoDistribucionReglas::preferencia(['interpretacion_estado'=>'SIN_PREFERENCIA']);
$assert($libre['tipo']==='LIBRE','sin preferencia permitido');
$assert(ProspectoDistribucionReglas::turno([
    6=>['desde'=>'09:00','hasta'=>'13:00']
],$libre,$ahora)['fecha_jornada']==='2026-11-07','siguiente turno sábado');
$assert(ProspectoDistribucionReglas::turno([
    5=>['desde'=>'08:00','hasta'=>'12:00']
],$libre,$ahora)['fecha_jornada']==='2026-11-13','turno expirado usa próxima semana');
$elegido=ProspectoDistribucionReglas::elegir([
    ['usuario_id'=>1,'meta_dia'=>79,'total_activos'=>60],
    ['usuario_id'=>2,'meta_dia'=>11,'total_activos'=>100]
],101,'META');
$assert($elegido['usuario_id']===2,'Meta equilibra primero cupo diario');
$elegido=ProspectoDistribucionReglas::elegir([
    ['usuario_id'=>1,'total_activos'=>9],['usuario_id'=>2,'total_activos'=>3]
],101,'ALIADO');
$assert($elegido['usuario_id']===2,'Aliado equilibra asignaciones activas');
echo 'OK: '.$checks.' reglas de Meta, zona, horario y reparto.\n';
