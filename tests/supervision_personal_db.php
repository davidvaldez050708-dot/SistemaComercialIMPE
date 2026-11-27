<?php
declare(strict_types=1);
// Sólo servidor local efímero. Jamás carga la conexión de producción.
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$port=(int)(getenv('SP_TEST_PORT')?:33319);
if(!in_array($port,[33319,33320],true))throw new RuntimeException('Puerto de prueba no permitido.');
$db=new mysqli('127.0.0.1','root','',null,$port);$db->set_charset('utf8mb4');
$database='impe_sp_test_'.bin2hex(random_bytes(4));$db->query('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');$db->select_db($database);
require __DIR__.'/../app/services/SupervisionPersonalAcceso.php';
require __DIR__.'/../app/services/SupervisionPersonalMigracion.php';
function check($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejected(callable $fn,string $message):void{try{$fn();}catch(DomainException|InvalidArgumentException $e){return;}throw new RuntimeException($message);}
function team(mysqli $db,int $kam,int $role,string $context='llamadas'):array{return array_map('intval',array_column((new SupervisionPersonalAcceso($db))->personas($kam,$context,$role)??[],'id'));}
try{
 foreach([
 'CREATE TABLE roles(id INT PRIMARY KEY,estado TINYINT,nombre VARCHAR(100))',
 'CREATE TABLE usuarios(id INT PRIMARY KEY,rol_id INT,estado TINYINT,nombre VARCHAR(100),apellidos VARCHAR(100),foto_perfil VARCHAR(250) DEFAULT "")',
 'CREATE TABLE estados(id INT PRIMARY KEY,estado TINYINT,nombre VARCHAR(100),nombre_corto VARCHAR(30) DEFAULT "",clave_inegi VARCHAR(4) DEFAULT "")',
 'CREATE TABLE asignaciones_territorio(id INT PRIMARY KEY,usuario_id INT,estado_id INT,tipo_asignacion VARCHAR(30),cuenta_clave_asignacion_id INT,activo TINYINT,fecha_inicio DATE NULL,fecha_fin DATE NULL)',
 'CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,estado_id INT,analista_id INT,activo TINYINT)',
 'CREATE TABLE interacciones_vinculacion(id INT PRIMARY KEY,seguimiento_id INT,usuario_id INT,canal VARCHAR(20),fecha_inicio DATETIME)',
 'CREATE TABLE permisos(id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(100),codigo VARCHAR(100) UNIQUE,nombre VARCHAR(100),descripcion TEXT,estado TINYINT)',
 'CREATE TABLE rol_permisos(rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id))',
 "INSERT INTO roles VALUES(1,1,'Administrador'),(2,1,'Director'),(3,1,'Asesor'),(4,1,'Analista de Datos'),(5,1,'Marketing'),(6,1,'Cuenta Clave')",
 "INSERT INTO usuarios(id,rol_id,estado,nombre,apellidos) VALUES(1,1,1,'Admin','Prueba'),(2,2,1,'Dirección','Prueba'),(10,6,1,'José','Prueba'),(11,6,1,'Sarahi','Prueba'),(20,3,1,'Mayra','Prueba'),(21,4,1,'Ana','Prueba'),(22,4,1,'Luis','Prueba'),(23,4,1,'Rosa','Prueba'),(24,4,0,'Inactivo','Prueba'),(25,3,0,'Asesor inactivo','Prueba'),(30,5,1,'Marketing','Prueba')",
 "INSERT INTO estados(id,estado,nombre) VALUES(1,1,'Jalisco'),(2,1,'CDMX')",
 "INSERT INTO asignaciones_territorio VALUES(1,10,1,'CUENTA_CLAVE',NULL,1,NULL,NULL),(2,11,2,'CUENTA_CLAVE',NULL,1,NULL,NULL),(3,22,1,'ANALISTA_DATOS',1,1,NULL,NULL),(4,21,2,'ANALISTA_DATOS',2,1,NULL,NULL),(5,20,2,'ASESOR',2,1,NULL,NULL),(6,24,1,'ANALISTA_DATOS',1,1,NULL,NULL),(7,23,2,'ANALISTA_DATOS',999,1,NULL,NULL),(8,22,1,'ANALISTA_DATOS',1,1,NULL,NULL),(9,21,1,'ANALISTA_DATOS',1,1,'2020-01-01','2020-01-02')",
 "INSERT INTO seguimientos_vinculacion VALUES(100,1,22,1),(101,2,21,1),(102,2,23,1),(103,1,23,1)",
 "INSERT INTO interacciones_vinculacion VALUES(1,100,22,'LLAMADA_IP',UTC_TIMESTAMP()),(2,100,23,'CORREO',UTC_TIMESTAMP()),(3,101,21,'LLAMADA_IP',UTC_TIMESTAMP()),(4,102,23,'CORREO',UTC_TIMESTAMP())",
 ]as$sql)$db->query($sql);
 $codes=['llamadas.supervision.ver','llamadas.supervision.audio','reportes.ver','reportes.ventas','reportes.ventas.equipo','reportes.seguimiento.actividad','seguimientos_vinculacion.ver','seguimientos_vinculacion.supervisar','desempeno.ver','desempeno.ver_equipo'];
 foreach($codes as$code){$s=$db->prepare("INSERT INTO permisos(modulo,codigo,nombre,descripcion,estado) VALUES('Prueba',?,'Prueba','Prueba',1)");$s->bind_param('s',$code);$s->execute();$db->query('INSERT INTO rol_permisos VALUES(6,'.$s->insert_id.')');$s->close();}
 $operativo=static fn()=>hash('sha256',json_encode($db->query('SELECT * FROM asignaciones_territorio ORDER BY id')->fetch_all(MYSQLI_ASSOC)));
 $original=$operativo();check(!(new SupervisionPersonalAcceso($db))->activa(),'Sin migración debe conservar comportamiento heredado.');
 $plan=SupervisionPersonalAuditoria::leer($db);check(count($plan['relaciones'])===4,'Deduplicar relaciones operativas explícitas.');check(count($plan['requieren_revision'])>=2,'Detectar relaciones huérfanas y exposición por territorio.');
 check($plan['resumen']['grupos_duplicados']===1,'Informar las asignaciones vigentes repetidas sin borrarlas.');
 $alterado=$plan;$alterado['relaciones'][]=['kam_id'=>10,'colaborador_id'=>30];
 rejected(fn()=>SupervisionPersonalAuditoria::resolver($alterado,[]),'No aceptar un plan editado con la huella anterior.');
 rejected(fn()=>SupervisionPersonalAuditoria::resolver($plan,[]),'Las ambigüedades necesitan autorización expresa.');
 $saved=[];$migration=new SupervisionPersonalMigracion($db);$migration->preparar($plan,function($r)use(&$saved){$saved[]=$r;},1);
 $migration->preparar($plan,function($r)use(&$saved){$saved[]=$r;},1);check(count($saved)===1,'Preparación idempotente y con respaldo previo.');
 check(!(new SupervisionPersonalAcceso($db))->activa(),'Preparar no activa autorizaciones.');check($operativo()===$original,'No cambiar territorios.');
 $repo=new SupervisionPersonalRepository($db);rejected(fn()=>$repo->catalogo(2),'Dirección no administra asignaciones.');rejected(fn()=>$repo->catalogo(10),'KAM no administra asignaciones.');
 rejected(fn()=>$migration->activar(1,$plan,[],'incorrecto'),'No activar un plan sin confirmación.');
 $decisions=[];foreach($plan['requieren_revision']as$i)$decisions[$i['id']]=['accion'=>'conservar_sin_migrar','motivo'=>'Revisión de prueba: conservar la relación operativa sin conceder supervisión adicional.'];
 $hashRevision=SupervisionPersonalAuditoria::hashRevision($plan,$decisions);
 $otrasDecisiones=$decisions;$otrasDecisiones[array_key_first($otrasDecisiones)]['motivo']='Decisión diferente que necesita una aprobación nueva.';
 rejected(fn()=>$migration->activar(1,$plan,$otrasDecisiones,$hashRevision),'La aprobación debe incluir las decisiones exactas.');
 $migration->activar(1,$plan,$decisions,$hashRevision);
 $equipo=team($db,10,4);sort($equipo);check($equipo===[22,24],'Importar equipo legítimo e históricos inactivos.');
 check(team($db,10,3)===[],'Compartir territorio no concede asesores.');check(team($db,11,3)===[20],'Conservar equipo de Sarahi.');
 $secret=str_repeat('test-secret-',4);$revision=$repo->estado()['revision'];
 $preview=$repo->previsualizar(1,10,[20,22,24],$revision,$secret);
 check($preview['agregar']===[20]&&$preview['retirar']===[],'Resumen exacto del alta sin cambiar otros miembros.');
 rejected(fn()=>$repo->guardar(1,10,[20,22,24],$revision,str_repeat('a',64),$secret),'No aceptar una selección sin confirmación válida.');
 $res=$repo->guardar(1,10,[20,22,24],$revision,$preview['confirmacion'],$secret);
 $rows=(int)$db->query('SELECT COUNT(*) n FROM supervision_personal_bitacora')->fetch_assoc()['n'];
 $again=$repo->guardar(1,10,[20,22,24],$revision,$preview['confirmacion'],$secret);
 check(!empty($again['idempotente'])&&(int)$db->query('SELECT COUNT(*) n FROM supervision_personal_bitacora')->fetch_assoc()['n']===$rows,'No duplicar guardados ante una respuesta perdida.');
 check(team($db,10,3)===[20]&&team($db,11,3)===[20],'Un colaborador puede tener dos KAM sin restricciones de estado.');
 check($operativo()===$original,'Asignar a Mayra no cambia CDMX, Jalisco ni su Cuenta Clave operativa.');
 $db->begin_transaction();try{
  $db->query("INSERT INTO supervision_personal(kam_id,colaborador_id,origen) VALUES(10,999,'PRUEBA')");
  $detalle=$repo->detalle(1,10);$orphan=array_values(array_filter($detalle['colaboradores'],static fn($p)=>(int)$p['id']===999));
  check(count($orphan)===1&&(int)$orphan[0]['seleccionado']===1&&(int)$orphan[0]['requiere_revision']===1,'Un usuario eliminado no desaparece de la selección ni se retira silenciosamente.');
 }finally{$db->rollback();}
 rejected(fn()=>$repo->previsualizar(1,10,[20,25],$res['revision'],$secret),'No agregar usuarios inactivos ajenos.');
 rejected(fn()=>$repo->previsualizar(1,10,[20,30],$res['revision'],$secret),'No agregar perfiles distintos de analista/asesor.');
 rejected(fn()=>$repo->previsualizar(1,10,['1 OR 1=1'],$res['revision'],$secret),'No aceptar inyección en identificadores.');
 $stale=$repo->previsualizar(1,11,[20],$res['revision'],$secret);
 $remove=$repo->previsualizar(1,10,[22,24],$res['revision'],$secret);$repo->guardar(1,10,[22,24],$res['revision'],$remove['confirmacion'],$secret);
 rejected(fn()=>$repo->guardar(1,11,[20],$res['revision'],$stale['confirmacion'],$secret),'Bloquear sobrescritura concurrente.');
 check(team($db,10,3)===[]&&team($db,11,3)===[20],'Retirar en un KAM no retira al otro.');
 $scope=(new SupervisionPersonalAcceso($db))->sql('i.usuario_id',10,'actividad_analistas',4);
 $ids=array_map('intval',array_column($db->query('SELECT i.id FROM interacciones_vinculacion i WHERE '.$scope)->fetch_all(MYSQLI_ASSOC),'id'));
 check($ids===[1],'No filtrar otros autores de un expediente compartido.');
 require __DIR__.'/supervision_personal_integracion.php';
 $db->query("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo='llamadas.supervision.audio' AND rp.rol_id=6");
 rejected(fn()=>(new SupervisionPersonalAcceso($db))->personas(10,'grabaciones',4),'La relación no sustituye el permiso de audio.');
 check((int)$db->query('SELECT COUNT(*) n FROM supervision_personal_bitacora WHERE tipo="MANUAL"')->fetch_assoc()['n']===2,'Registrar altas y bajas con responsable y cambios.');
 $migration->desactivar(1);check(!(new SupervisionPersonalAcceso($db))->activa(),'Reversión devuelve autorizaciones al modo anterior.');
 check((int)$db->query('SELECT COUNT(*) n FROM supervision_personal')->fetch_assoc()['n']===5,'La reversión conserva las asignaciones nuevas y su historia.');
 check($operativo()===$original,'No hay cambios operativos después de preparar, activar, editar y revertir.');
 echo "PASS: auditoría y ambigüedades; preparación inactiva e idempotente; activación expresa; dos KAM; revisión firmada; CSRF ligado al token; concurrencia; revocación inmediata; permisos de audio; integridad territorial; bitácora y reversión sin borrar datos.\n";
}finally{
 if(getenv('SP_KEEP_DATABASE')==='1'){
  $db->query('UPDATE supervision_personal_estado SET activo=1,revision=revision+1 WHERE id=1');
  file_put_contents(__DIR__.'/../tmp/sp-test-state.json',json_encode(['database'=>$database,'port'=>$port]));
 }else{$db->query('DROP DATABASE '.$database);}
 $db->close();
}
