<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','',null,33319);
$database='impe_prospectos_hora_'.bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($database);$db->set_charset('utf8mb4');
$GLOBALS['prospectos_hora_db']=$db;
$project=getenv('PROSPECTOS_HORA_PROJECT');$source=getenv('PROSPECTOS_HORA_SOURCE');
function check($ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
try {
 $db->query("SET time_zone='+00:00'");
 $db->query('SET timestamp=1793112814'); // 27/10/2026 14:53:34 UTC.
 $db->query('CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(80),estado INT)');
 $db->query('CREATE TABLE municipios(id INT PRIMARY KEY,nombre VARCHAR(80))');
 $ddl=file_get_contents($source.'/database/migrations/2026_10_08_formulario_registros.sql');
 preg_match('/CREATE TABLE IF NOT EXISTS formulario_registros\s*\(.*?\) ENGINE=.*?;/s',$ddl,$match);
 check(isset($match[0]),'Falta el esquema canónico');$db->query($match[0]);
 $db->query('ALTER TABLE formulario_registros ADD tel_recomendado VARCHAR(10) NULL, ADD horario_contacto VARCHAR(255) NULL');
 $db->query('CREATE TABLE formulario_aliado_procedencias(id INT PRIMARY KEY,tipo VARCHAR(20),registro_id INT,aliado_id INT,aliado_nombre VARCHAR(80),programa_codigo VARCHAR(80))');
 $db->query('CREATE TABLE formulario_aliados(id INT PRIMARY KEY,seguimiento_id INT,nombre VARCHAR(80))');
 $db->query('CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,estado_id INT)');
 $db->query('CREATE TABLE formulario_programas(codigo VARCHAR(80),nombre VARCHAR(80))');
 $db->query("INSERT INTO estados VALUES(2,'Baja California',1),(11,'Guanajuato',1)");
 $db->query("INSERT INTO municipios VALUES(1,'Prueba')");
 $db->query('INSERT INTO seguimientos_vinculacion VALUES(1,2),(2,11)');
 $db->query("INSERT INTO formulario_aliados VALUES(1,1,'Aliado Uno'),(2,2,'Aliado Dos')");
 $db->query("INSERT INTO formulario_programas VALUES('ejecutivas','Ejecutivas'),('ingles','Inglés')");
 $availability='Lun, Mar, Vie · 07:00–22:00 (hora local)';
 $insert=$db->prepare("INSERT INTO formulario_registros(id,nombre,apellido,fecha_nacimiento,movil,correo,perfil_interes,lugar_laboras,estado_id,municipio_id,origen,horario_contacto,created_at) VALUES(?,?,'Prueba','2000-01-01','5555555555','hora@example.test','Interés','Prueba',11,1,?,?,?)");
 foreach([
  [1,'General','MARKETING','2026-10-24 19:18:40'],
  [2,'Público','PUBLICO','2026-10-27 02:15:00'],
  [3,'Anterior','PUBLICO','2026-10-23 22:15:36'],
  [4,'Empate','MARKETING','2026-10-24 19:18:40']
 ] as [$id,$name,$origin,$created]) {
  $insert->bind_param('issss',$id,$name,$origin,$availability,$created);$insert->execute();
 }
 $db->query("INSERT INTO formulario_aliado_procedencias VALUES(1,'registro',2,1,'Aliado Uno','ejecutivas'),(2,'registro',3,2,'Aliado Dos','ingles'),(3,'registro',4,1,'Aliado Uno','ejecutivas')");
 // El escritor vigente usa NOW(); ambos canales obtienen UTC con la sesión real del sitio.
 $db->query("INSERT INTO formulario_registros(nombre,apellido,fecha_nacimiento,movil,correo,perfil_interes,lugar_laboras,estado_id,municipio_id,origen,created_at,updated_at) VALUES('Nuevo','Interno','2000-01-01','5555555555','nuevo@example.test','Interés','Prueba',11,1,'MARKETING',NOW(),NOW())");
 $before=$db->query('SELECT * FROM formulario_registros ORDER BY id')->fetch_all(MYSQLI_ASSOC);
 require $project.'/app/models/ProspectoModel.php';$model=new ProspectoModel();
 foreach(['America/Mexico_City','UTC','Asia/Tokyo'] as $phpZone) {
  date_default_timezone_set($phpZone);
  foreach(['+00:00','-06:00','+05:30'] as $sqlZone) {
   $db->query("SET time_zone='$sqlZone'");
   $rows=$model->listar(1,100);$byId=array_column($rows['registros'],null,'id');
   check($byId[1]['created_at']==='2026-10-24 13:18:40','Registro histórico UTC sin convertir');
   check($byId[2]['created_at']==='2026-10-26 20:15:00','No cruza al día anterior');
   check($byId[5]['created_at']==='2026-10-27 08:53:34','Registro nuevo incorrecto');
   check(array_column($rows['registros'],'id')===[5,2,4,1,3],'Cambió el orden de registros');
   check($model->listar(2,2)['registros'][0]['id']===4,'Paginación inconsistente');
   foreach($byId as $id=>$row) {
    $stored=$before[$id-1];foreach($row as $key=>$value) {
     if($key==='created_at'||!array_key_exists($key,$stored))continue;
     check($key==='id'?(int)$value===(int)$stored[$key]:$value===$stored[$key],'Alteró un campo: '.$key);
    }
   }
   $territory=$model->listar(1,100,['estado_id'=>2]);
   check(array_column($territory['registros'],'id')===[2,4],'Perdió el territorio del aliado');
   $filtered=$model->listar(1,100,['estado_id'=>2,'aliado_id'=>'1','programa'=>ProspectoModel::clavePrograma('a','ejecutivas'),'buscar'=>'Público']);
   check($filtered['total']===1&&$filtered['registros'][0]['id']===2,'Filtros combinados incorrectos');
   check($filtered['registros'][0]['horario_contacto']===$availability,'Convirtió la disponibilidad local');
  }
 }
 check($before===$db->query('SELECT * FROM formulario_registros ORDER BY id')->fetch_all(MYSQLI_ASSOC),'La consulta modificó datos');
 foreach([null,'','0000-00-00 00:00:00','2026-02-30 12:00:00','texto'] as $invalid)check(ProspectoFechaHoraHelper::aMexico($invalid)===$invalid,'Fecha inválida convertida a dato inventado');
 // Las fechas históricas usan reglas IANA, sin restar seis horas indiscriminadamente.
 check(ProspectoFechaHoraHelper::aMexico('2021-07-01 12:00:00')==='2021-07-01 07:00:00','Perdió la zona IANA histórica');
 putenv('PROSPECTOS_HORA_DATABASE='.$database);
 $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($source.'/tools/verificar_hora_prospectos.php').' --site '.escapeshellarg($project);
 exec($command,$output,$status);check($status===0,'Comprobador CLI falló');
 $report=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
 check($report['registro_mas_reciente_mexico']==='2026-10-27 08:53:34','Comprobador no convierte correctamente');
 check(!str_contains(implode('', $output),'example.test')&&!str_contains(implode('', $output),'5555555555'),'Comprobador expone datos personales');
 echo "PASS: históricos y nuevos, cambio de día, nueve combinaciones de zonas PHP/SQL, filtros territoriales/aliado/programa/búsqueda, paginación y datos intactos; comprobación CLI sin datos personales.\n";
} finally {
 $db->query("DROP DATABASE `$database`");
}
