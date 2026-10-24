<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_horario_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require __DIR__.'/../config/db_connection.php';
// Se crean las ligas con el servicio anterior, antes de cargar la nueva versión.
require $argv[1];
$db=(new Database())->connect(false);$service=new RegistroEnlaceService($db);
$ally=$db->query('SELECT id,token FROM formulario_aliados ORDER BY id LIMIT 1')->fetch_assoc();
$long=BASE_URL.'index.php?controller=formularioPublico&action=registro';
$general=$service->acortar($long);$url=$service->acortar($long.'&aliado='.$ally['token']);
$context=$service->acortar($long.'&aliado='.$ally['token'].'&convocatoria_ref=beca-contexto&estado_id=1&titulo=Prueba');
if(!str_contains($url,'/el-primer-paso-hacia-tu-meta-comienza-aqui/'))throw new RuntimeException('La fixture no usa el servicio anterior');
$db->query('CREATE TABLE convocatorias(id INT PRIMARY KEY,enlace_registro TEXT) ENGINE=InnoDB');
$q=$db->prepare('INSERT INTO convocatorias VALUES(1,?),(2,?)');$oldLong=$long.'&aliado='.$ally['token'];$q->bind_param('ss',$url,$oldLong);$q->execute();
$snapshot=['aliases'=>$db->query('SELECT * FROM registro_enlaces_amigables ORDER BY codigo')->fetch_all(MYSQLI_ASSOC),'aliados'=>$db->query('SELECT * FROM formulario_aliados ORDER BY id')->fetch_all(MYSQLI_ASSOC),'programas'=>$db->query('SELECT * FROM formulario_aliado_programas ORDER BY aliado_id,programa_codigo')->fetch_all(MYSQLI_ASSOC),'convocatorias'=>$db->query('SELECT * FROM convocatorias ORDER BY id')->fetch_all(MYSQLI_ASSOC),'url'=>$url,'general'=>$general,'contexto'=>$context,'aliado_id'=>(int)$ally['id'],'token'=>$ally['token']];
if(!getenv('IMPE_BECA_SNAPSHOT'))exit(2);
file_put_contents(getenv('IMPE_BECA_SNAPSHOT'),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo "PASS ligas y convocatorias sintéticas creadas con el servicio anterior\n";
