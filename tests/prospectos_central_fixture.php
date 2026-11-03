<?php
if (PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_prospectos_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')) exit(2);
require __DIR__.'/../config/db_connection.php';require __DIR__.'/../app/services/ProspectoCentralIncorporacionService.php';
$db=(new Database())->connect(false);
if (($argv[1]??'')==='--drop') {$db->query('DROP DATABASE `'.getenv('IMPE_DB_NAME').'`');exit;}
if (($argv[1]??'')==='--snapshot') {
    $rows=[];foreach ($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$table]) $rows[$table]=$db->query('SELECT * FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC);
    echo hash('sha256',json_encode($rows));exit;
}
if (($argv[1]??'')==='--seed') {
    $svc=new ProspectoCentralIncorporacionService($db);
    for ($i=1;$i<=35;$i++) {
        $name='Prospecto ficticio '.$i;$email='fixture'.$i.'@example.test';$s=$db->prepare("INSERT INTO formulario_registros(nombre,apellido,fecha_nacimiento,movil,correo,perfil_interes,lugar_laboras,estado_id,municipio_id,origen,created_at,updated_at,horario_contacto) VALUES (?,'Prueba','1990-01-01','5500000010',?,'Programa A (prueba)','Empresa ficticia',1,1,'MARKETING','2026-11-02 15:00:00','2026-11-02 15:00:00','Sin preferencia')");$s->bind_param('ss',$name,$email);$s->execute();$svc->incorporarRegistro((int)$db->insert_id);
    }
    $xss='<img src=x onerror=alert(1)>';$s=$db->prepare('UPDATE prospectos_comerciales SET nombre_completo=? WHERE id=1');$s->bind_param('s',$xss);$s->execute();
    $db->query("INSERT INTO formulario_aliado_procedencias(aliado_id,tipo,registro_id,programa_codigo,aliado_nombre,clave) SELECT 1,'registro',id,'programa-a','Aliado de prueba',SHA2(CONCAT('fixture:',id),256) FROM formulario_registros WHERE nombre LIKE 'Prospecto ficticio %'");
    foreach ($db->query("SELECT id FROM formulario_registros WHERE nombre LIKE 'Prospecto ficticio %'")->fetch_all(MYSQLI_ASSOC) as $r) $svc->incorporarRegistro((int)$r['id']);
    echo 'FIXTURE READY';exit;
}
exit(2);
