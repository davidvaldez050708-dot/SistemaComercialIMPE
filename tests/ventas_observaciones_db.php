<?php
require __DIR__.'/supervision_llamadas_db.php';
require __DIR__.'/../app/services/TelefoniaObservacionesVentasService.php';
foreach(['telefonia.usar','telefonia.recibir','telefonia.salientes'] as $permission){
    $s=$db->prepare("INSERT IGNORE INTO permisos(modulo,codigo,nombre,descripcion) VALUES('Telefonía',?,?,'Prueba')");$s->bind_param('ss',$permission,$permission);$s->execute();
    $s=$db->prepare('INSERT IGNORE INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo=?');$s->bind_param('s',$permission);$s->execute();
}
$notes=new TelefoniaObservacionesVentasService($db);
qcheck(!$notes->propias(301,'report-call-direct','150')['disponible'],'Lectura sin migración no escribe');
$db->query(file_get_contents(__DIR__.'/../database/migrations/2026_10_26_ventas_observaciones_adicionales.sql'));
$before=[];
foreach(['telefonia_ventas_resultados','telefonia_ventas_llamadas','telefonia_zadarma_eventos','telefonia_ventas_evidencia','telefonia_contactos_personales','telefonia_disponibilidad','telefonia_transferencia_operaciones'] as $t)$before[$t]=$db->query("SELECT * FROM $t")->fetch_all(MYSQLI_ASSOC);
$token=str_repeat('a',32);$text="Olvidé indicar que solicitó información.\n<script>alert(1)</script>";
$first=$notes->agregar(301,'report-call-direct','150',$text,$token);
qcheck($first['autor']==='Asesor Propio'&&$first['nota']===$text,'Autor real y nota literal');
$stored=$db->query('SELECT created_at FROM telefonia_ventas_observaciones WHERE id='.(int)$first['id'])->fetch_assoc();
$mx=(new DateTimeImmutable($stored['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'));
qcheck($first['fecha_iso']===$mx->format(DateTimeInterface::ATOM),'Fecha persistida UTC y presentada en México');
qcheck($notes->agregar(301,'report-call-direct','150',$text,$token)===$first,'Reintento idempotente');
foreach([[302,'report-call-direct','150','Ajena',str_repeat('b',32)],
 [301,'report-call-direct','151','Otra extensión',str_repeat('b',32)],
 [301,'REPORT-CALL-DIRECT','150','Referencia diferente',str_repeat('b',32)],
 [301,'no-result-call','150','Sin resultado',str_repeat('b',32)],
 [301,'report-call-direct','150','Texto cambiado',$token],
 [301,'report-call-direct','150',' ',str_repeat('b',32)],
 [301,'report-call-direct','150',str_repeat('á',1001),str_repeat('b',32)],
 [301,'report-call-direct','150',"abc\0def",str_repeat('b',32)],
 [301,'report-call-direct','150','Nota','token-invalido']] as $args){
    try{$notes->agregar(...$args);throw new LogicException('Guardado indebido');}catch(DomainException|InvalidArgumentException $e){}
}
$max=$notes->agregar(301,'report-call-transfer','150',str_repeat('ñ',1000),str_repeat('c',32));
qcheck(mb_strlen($max['nota'])===1000,'Límite Unicode');
$db->query("UPDATE usuarios SET nombre='Nombre actualizado' WHERE id=301");
qcheck($notes->propias(301,'report-call-direct','150')['observaciones'][0]['autor']==='Asesor Propio','Autor histórico conservado');
$db->query("UPDATE usuarios SET nombre='Asesor' WHERE id=301");
foreach([10,20,601] as $supervisor)qcheck($notes->supervisadas($supervisor,'venta:1')['observaciones']==[$first],'Supervisor autorizado');
try{$notes->supervisadas(602,'venta:1');throw new LogicException('Equipo ajeno');}catch(DomainException $e){}
try{$notes->supervisadas(302,'venta:1');throw new LogicException('Asesor supervisa');}catch(DomainException $e){}
$db->query("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=3 AND p.codigo='telefonia.usar'");
try{$notes->agregar(301,'report-call-direct','150','Permiso revocado',str_repeat('d',32));throw new LogicException('Revocación ignorada');}catch(DomainException $e){}
$db->query("INSERT INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo='telefonia.usar'");
$db->query("UPDATE asignaciones_territorio SET activo=0 WHERE id=3");
try{$notes->supervisadas(601,'venta:1');throw new LogicException('Equipo revocado');}catch(DomainException $e){}
$db->query('UPDATE asignaciones_territorio SET activo=1 WHERE id=3');
foreach($before as $t=>$rows)qcheck($db->query("SELECT * FROM $t")->fetch_all(MYSQLI_ASSOC)===$rows,'Las notas modificaron '.$t);
qcheck($db->query('SELECT COUNT(*) n FROM telefonia_ventas_observaciones')->fetch_assoc()['n']==2,'Sin duplicados ni guardados indebidos');
echo "Observaciones SQL: anexos inmutables, autor histórico, UTC/México, identidad exacta, permisos/equipo, Unicode, reintentos y datos originales intactos OK\n";
