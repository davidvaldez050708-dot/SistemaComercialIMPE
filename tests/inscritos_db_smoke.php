<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')throw new RuntimeException('Pruebas solo en CLI testing');
$h=explode(':',getenv('IMPE_DB_HOST')?:'127.0.0.1:33339',2);
$db=new mysqli($h[0],getenv('IMPE_DB_USER')?:'root',getenv('IMPE_DB_PASSWORD')?:'','',(int)($h[1]??3306));
$db->set_charset('utf8mb4');
$name='impe_test_inscritos_'.bin2hex(random_bytes(4));
if(!preg_match('/^impe_test_inscritos_[a-f0-9]{8}$/D',$name))throw new RuntimeException('Nombre de BD inválido');
$db->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->select_db($name);
function afirma(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException('FAIL inscritos: '.$msg);}
try{
 $db->query('CREATE TABLE formulario_inscripciones(
   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(80),
   apellido VARCHAR(120),correo VARCHAR(190),movil VARCHAR(15),
   origen VARCHAR(30),created_at DATETIME) ENGINE=InnoDB');
 $db->query('CREATE TABLE formulario_inscripciones_completas(
   inscripcion_id INT UNSIGNED PRIMARY KEY,programa_slug VARCHAR(80),
   datos LONGTEXT CHECK(JSON_VALID(datos)),aceptado_at DATETIME,
   comprobante_mime VARCHAR(50),comprobante_tamano INT,
   terminos_sha256 CHAR(64),privacidad_sha256 CHAR(64)) ENGINE=InnoDB');
 require_once dirname(__DIR__).'/app/models/InscritosModel.php';
 require_once dirname(__DIR__).'/app/services/InscritosExcelService.php';
 require_once dirname(__DIR__).'/app/helpers/InscritosPermisos.php';
 $names=[
  ['María','García','maria@example.test','5511111111','PUBLICO','2026-11-05 05:30:00','bachillerato-286','GARR000101HDFRRSA0'],
  ['=2+2','Valdez','vale@example.test','5522222222','MARKETING','2026-11-05 06:30:00','titulacion-ejecutivas','VALD000101HDFRRSA0'],
  ['Luis','Martínez','luis@example.test','5533333333','PUBLICO','2026-11-06 07:15:00','titulacion-experiencia-laboral','MART000101HDFRRSA0']
 ];
 $insert=$db->prepare('INSERT INTO formulario_inscripciones(nombre,apellido,correo,movil,origen,created_at) VALUES(?,?,?,?,?,?)');
 $detail=$db->prepare('INSERT INTO formulario_inscripciones_completas(inscripcion_id,programa_slug,datos,aceptado_at,comprobante_mime,comprobante_tamano,terminos_sha256,privacidad_sha256) VALUES(?,?,?,?,"application/pdf",500,?,?)');
 $hash=str_repeat('a',64);
 foreach($names as $n){
  [$nombre,$apellido,$correo,$movil,$origen,$created,$programa,$curp]=$n;
  $insert->bind_param('ssssss',$nombre,$apellido,$correo,$movil,$origen,$created);$insert->execute();
  $id=(int)$db->insert_id;
  $datos=json_encode(['curp'=>$curp,'calle'=>'Calle sintética','fecha_inscripcion'=>'2026-11-05','perfil_evaluacion'=>'Ingeniería industrial A286'],JSON_UNESCAPED_UNICODE);
  $detail->bind_param('isssss',$id,$programa,$datos,$created,$hash,$hash);$detail->execute();
 }
 $m=new InscritosModel($db);$all=InscritosModel::filtros([]);
 afirma($m->listar($all)['total']===3,'cantidad total');
 afirma($m->listar($all,2)['paginas']===2,'paginación');
 $p2=$all;$p2['pagina']=2;
 afirma(count($m->listar($p2,2)['registros'])===1,'segunda página');
 foreach([
  [['programa'=>'bachillerato-286'],1],
  [['buscar'=>'maria@example.test'],1],
  [['buscar'=>'552222'],1],
  [['buscar'=>'Valdez'],1],
  [['buscar'=>'MART000101'],1],
  [['buscar'=>"' OR 1=1 --"],0],
  [['buscar'=>'%'],0],
  [['desde'=>'2026-11-05','hasta'=>'2026-11-05'],1]
 ] as $case){
  [$f,$count]=$case;
  afirma($m->listar(InscritosModel::filtros($f))['total']===$count,'filtro '.json_encode($f));
 }
 $date=InscritosModel::filtros(['desde'=>'2026-11-05','hasta'=>'2026-11-05']);
 afirma((int)$m->listar($date)['registros'][0]['id']===2,'fecha local CDMX inclusiva');
 foreach([['programa'=>'invalido'],['desde'=>'2026-02-31'],['desde'=>'2026-11-07','hasta'=>'2026-11-01'],['buscar'=>str_repeat('a',101)]] as $bad){
  try{InscritosModel::filtros($bad);throw new RuntimeException('Filtro inválido aceptado');}
  catch(DomainException $e){afirma($e->getCode()===422,'error de filtro');}
 }
 $ficha=$m->detalle(1);
 afirma($ficha!==null&&$ficha['datos']['calle']==='Calle sintética','ficha completa');
 afirma(!isset($ficha['solicitud_token'])&&$m->detalle(9999)===null,'privacidad detalle');
 afirma(count($m->exportar($p2))===3,'Excel ignora paginación');
 afirma(count($m->exportar(InscritosModel::filtros(['programa'=>'bachillerato-286'])))===1,'Excel conserva filtro');
 try{$m->exportar($all,2);throw new RuntimeException('Límite exportación no funcionó');}
 catch(DomainException $e){afirma($e->getCode()===422,'límite sin truncar');}
 $archivo=InscritosExcelService::crear($m->exportar($all),$all);
 try{
  $zip=new ZipArchive();
  afirma($zip->open($archivo)===true,'XLSX ZIP');
  $xml=(string)$zip->getFromName('xl/worksheets/sheet1.xml');
  afirma(strpos($xml,'INSCRIPCIONES RECIBIDAS')!==false&&strpos($xml,'autoFilter')!==false,'encabezado XLSX');
  afirma(strpos($xml,'=2+2')!==false&&strpos($xml,'<f>')===false,'prevención formula injection');
  afirma(strpos($xml,'Recibido (pendiente de revisión)')!==false,'estado de comprobante');
  afirma(substr_count($xml,'<row r=')===7,'4 filas de cabecera y 3 registros');
  afirma((string)$zip->getFromName('xl/styles.xml')!=='','estilos XLSX');
  $zip->close();
 }finally{unlink($archivo);}
 $_SESSION=['usuario_id'=>99,'rol_id'=>8,'rol'=>'Marketing','permisos'=>[]];
 afirma(!InscritosPermisos::puede(InscritosPermisos::VER),'sin permisos no hay consulta');
 $_SESSION['permisos']=[InscritosPermisos::VER];
 afirma(InscritosPermisos::puede(InscritosPermisos::VER)&&!InscritosPermisos::puede(InscritosPermisos::EXPORTAR),'lectura no autoriza Excel');
 $_SESSION['permisos']=[InscritosPermisos::VER,InscritosPermisos::EXPORTAR];
 afirma(InscritosPermisos::puede(InscritosPermisos::EXPORTAR),'permiso explícito Excel');
 $sidebar=file_get_contents(dirname(__DIR__).'/app/views/layout/sidebar.php');
 afirma(substr_count($sidebar,'Inscripciones recibidas')===2,'desktop y móvil');
 afirma(strpos(file_get_contents(dirname(__DIR__).'/index.php'),"case 'inscritos':")!==false,'router protegido');
 echo "PASS inscritos: búsqueda, filtros, fechas México, detalle, permisos, XLSX real y seguridad\n";
}finally{$db->query('DROP DATABASE IF EXISTS '.$name);}
