<?php
/** Pruebas de integración sólo en base desechable: nunca usar credenciales IMPE. */
require_once __DIR__.'/../app/services/ProspectoAsesorConfiguracionService.php';

if (getenv('IMPE_ASESORES_TEST_DB')!=='YES') {
    fwrite(STDERR,"Se exige IMPE_ASESORES_TEST_DB=YES para crear una base desechable.\n");
    exit(2);
}
$host=getenv('MYSQL_HOST')?:'127.0.0.1';
$user=getenv('MYSQL_USER')?:'root';
$password=getenv('MYSQL_PASSWORD')?:'';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($host,$user,$password,'',3306);
$db->set_charset('utf8mb4');
$name='impe_asesores_test_'.bin2hex(random_bytes(5));
$checkCount=0;
$check=function(bool $ok,string $label)use(&$checkCount):void {
    if (!$ok)throw new RuntimeException('FALLÓ: '.$label);
    $checkCount++;
};
try {
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    $db->query('CREATE TABLE roles(id INT NOT NULL PRIMARY KEY,nombre VARCHAR(60) NOT NULL,estado TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB');
    $db->query('CREATE TABLE usuarios(id INT NOT NULL PRIMARY KEY,nombre VARCHAR(100),apellidos VARCHAR(100),rol_id INT NOT NULL,estado TINYINT NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE formulario_programas(codigo VARCHAR(64) NOT NULL PRIMARY KEY,nombre VARCHAR(180) NOT NULL,activo TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->query('CREATE TABLE permisos(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(100),codigo VARCHAR(120) NOT NULL UNIQUE,nombre VARCHAR(200),descripcion TEXT,estado TINYINT NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE rol_permisos(rol_id INT NOT NULL,permiso_id INT NOT NULL,PRIMARY KEY(rol_id,permiso_id)) ENGINE=InnoDB');
    $db->query("INSERT INTO roles VALUES(1,'Administrador',1),(3,'Asesor de Ventas',1),(4,'Otro rol',1)");
    $db->query("INSERT INTO usuarios VALUES(1,'Admin','Uno',1,1),(3,'Mayra','Prueba',3,1),(4,'No','Asesor',4,1),(5,'Asesor','Inactivo',3,0)");
    $db->query("INSERT INTO formulario_programas VALUES('BACH286','Bachillerato 286',1),('TIT_EXP','Experiencia laboral',1),('BAJA','Inactivo',0)");
    $sql=file_get_contents(__DIR__.'/../database/migrations/2026_11_06_prospectos_asesores_config.sql');
    if ($sql===false||!$db->multi_query($sql))throw new RuntimeException('No se pudo ejecutar la migración en la BD aislada.');
    do {if ($res=$db->store_result())$res->free();} while($db->more_results()&&$db->next_result());
    $svc=new ProspectoAsesorConfiguracionService($db);
    $check($svc->disponible(),'estructura disponible');
    $check(count($svc->programas())===2,'solo programas vigentes');
    $check(count($svc->asesores(true))===2,'listado del rol correcto, incluso inactivos');
    $permiso=(int)$db->query("SELECT id FROM permisos WHERE codigo='prospectos.configurar_asesores'")->fetch_assoc()['id'];
    $check($permiso>0,'permiso registrado');
    $check((int)$db->query("SELECT COUNT(*) n FROM rol_permisos WHERE rol_id=1 AND permiso_id=$permiso")->fetch_assoc()['n']===1,'administración con permiso inicial');
    $_SESSION=['usuario_id'=>1,'rol_id'=>1,'permisos'=>['prospectos.ver_todos','prospectos.configurar_asesores']];
    $base=['programa_codigo'=>'BACH286','jornada'=>'MEDIO_TIEMPO','activo'=>'1','version'=>'0',
        'dias'=>[1=>['activo'=>'1','desde'=>'16:00','hasta'=>'21:00']]];
    $svc->guardar(3,1,$base);
    $c=$db->query('SELECT * FROM prospecto_asesores_config WHERE usuario_id=3')->fetch_assoc();
    $check($c['programa_codigo']==='BACH286'&&(int)$c['version']===1,'inserción versionada');
    $check((int)$db->query('SELECT COUNT(*) n FROM prospecto_asesores_horarios WHERE usuario_id=3')->fetch_assoc()['n']===1,'horario persistido');
    $check((int)$db->query('SELECT COUNT(*) n FROM prospecto_asesores_auditoria WHERE usuario_id=3')->fetch_assoc()['n']===1,'auditoría inicial');
    try {$svc->guardar(3,1,$base);throw new RuntimeException('Version duplicada aceptada');}
    catch(DomainException $e){$check(str_contains($e->getMessage(),'modificó'),'version obsoleta rechazada');}
    $update=$base;$update['version']='1';$update['jornada']='TIEMPO_COMPLETO';
    $update['dias'][2]=['activo'=>'1','desde'=>'09:00','hasta'=>'18:00'];
    $svc->guardar(3,1,$update);
    $check((int)$db->query('SELECT version FROM prospecto_asesores_config WHERE usuario_id=3')->fetch_assoc()['version']===2,'actualización versionada');
    $check((int)$db->query('SELECT COUNT(*) n FROM prospecto_asesores_horarios WHERE usuario_id=3')->fetch_assoc()['n']===2,'dos días guardados');
    $check((int)$db->query('SELECT COUNT(*) n FROM prospecto_asesores_auditoria WHERE usuario_id=3')->fetch_assoc()['n']===2,'auditoría actualizada');
    try {$svc->guardar(4,1,$base);throw new RuntimeException('Se aceptó rol ajeno');}
    catch(DomainException $e){$check(str_contains($e->getMessage(),'Asesor de Ventas'),'rol ajeno rechazado');}
    try {$svc->guardar(5,1,$base);throw new RuntimeException('Se aceptó asesor inactivo');}
    catch(DomainException $e){$check(str_contains($e->getMessage(),'activo'),'asesor inactivo rechazado');}
    $_SESSION['permisos']=['prospectos.ver_todos'];
    try {$svc->guardar(3,1,$base);throw new RuntimeException('Se aceptó usuario sin permiso');}
    catch(DomainException $e){$check(str_contains($e->getMessage(),'permiso'),'permiso efectivo obligatorio');}
    $check((int)$db->query('SELECT COUNT(*) n FROM prospecto_asesores_config')->fetch_assoc()['n']===1,'ningún asesor adicional configurado');
    echo "OK: $checkCount comprobaciones en BD desechable.\n";
} finally {
    $db->select_db('information_schema');
    $db->query("DROP DATABASE IF EXISTS `$name`");
    $db->close();
}
