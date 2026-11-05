<?php
// Base temporal explícita. No carga configuración ni credenciales del sitio.
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__);
foreach(['app/models/SeguimientoVinculacionModel.php','app/services/SeguimientoExcelImportService.php'] as $file){
    $source=file_get_contents($root.'/'.$file);
    eval('?>'.preg_replace('/^require_once .*;\s*$/m','',$source));
}
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');if(!$port||$port===3306)throw new RuntimeException('Puerto de pruebas aislado obligatorio.');
$db=new mysqli('127.0.0.1','root','',null,$port);$name='impe_test_excel_'.getmypid();
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
try{
    $db->query('CREATE TABLE municipios(id INT PRIMARY KEY,estado_id INT,nombre VARCHAR(80),estado INT)');
    $db->query("INSERT INTO municipios VALUES(17001,17,'Cuernavaca',1),(16001,16,'Morelia',1),(15001,15,'Toluca',1)");
    $db->query('CREATE TABLE seguimientos_vinculacion(id INT AUTO_INCREMENT PRIMARY KEY,estado_id INT,municipio_id INT,analista_id INT,origen VARCHAR(30),clave_origen VARCHAR(90),tipo_entidad VARCHAR(30),nombre_entidad VARCHAR(220),telefono_fuente VARCHAR(80),correo_fuente VARCHAR(180),telefono_verificado VARCHAR(80),whatsapp_verificado VARCHAR(80),correo_verificado VARCHAR(180),contacto_nombre VARCHAR(180),contacto_cargo VARCHAR(150),datos_verificados INT,estado_seguimiento VARCHAR(40),motivo_descarte TEXT,observaciones TEXT,ultima_interaccion_at DATETIME,proxima_accion_at DATETIME,fecha_inicio DATETIME,activo INT,actividad_giro VARCHAR(240),direccion_fuente VARCHAR(240),sitio_web_fuente VARCHAR(255)) ENGINE=InnoDB');
    $scope=[['id'=>17,'nombre'=>'Morelos'],['id'=>16,'nombre'=>'Michoacán'],['id'=>15,'nombre'=>'Estado de México']];
    $rows=[];
    foreach([['Morelos','Cuernavaca'],['Michoacan','Morelia'],['Estado de mexico','Toluca']] as $i=>[$state,$city]){
        $rows[]=['fila'=>$i+2,'celdas'=>['','Institución sintética '.($i+1),'INSTITUCION',$state,$city,'Educación','Calle de prueba '.($i+1),'555000000'.($i+1),'555000000'.($i+1),'ejemplo'.($i+1).'@example.test','https://example.test','Contacto sintético','Dirección','00 | Sin historial verificable','','','','','']];
    }
    $service=new SeguimientoExcelImportService($db);$preview=$service->previsualizar($rows,$scope,501,501);
    check($preview['resumen']['nuevas']===3,'Tres estados resueltos, con y sin acentos');
    check(array_column(array_column($preview['filas'],'registro'),'estado_id')===[17,16,15],'Estado de cada fila');
    check((int)$db->query('SELECT COUNT(*) n FROM seguimientos_vinculacion')->fetch_assoc()['n']===0,'Analizar no inserta');
    for($i=1;$i<=13;$i++){
        $missing=$rows[0];$missing['celdas'][$i]='';$result=$service->previsualizar([$missing],$scope,501,501);
        check($result['resumen']['invalidas']===1,'Cada uno de los 13 campos es obligatorio: '.$i);
    }
    $foreign=$service->previsualizar($rows,[$scope[0]],501,501);check($foreign['resumen']['invalidas']===2,'Territorios no autorizados no pasan');
    $wrong=$rows[0];$wrong['celdas'][4]='Morelia';check($service->previsualizar([$wrong],$scope,501,501)['resumen']['invalidas']===1,'Municipio corresponde al estado');
    try{$service->confirmar($preview['filas'],[$scope[0]],501,501);throw new RuntimeException('Revocación ignorada');}catch(DomainException $e){}
    check((int)$db->query('SELECT COUNT(*) n FROM seguimientos_vinculacion')->fetch_assoc()['n']===0,'Rollback completo si cambia el alcance');
    $saved=$service->confirmar($preview['filas'],$scope,501,501);check($saved['insertadas']===3,'Persistencia multiestado');
    $actual=$db->query('SELECT estado_id,municipio_id,analista_id,datos_verificados,estado_seguimiento,ultima_interaccion_at,proxima_accion_at FROM seguimientos_vinculacion ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    check(array_map('intval',array_column($actual,'estado_id'))===[17,16,15],'Cada institución guardada en su estado');
    foreach($actual as $r)check((int)$r['analista_id']===501&&(int)$r['datos_verificados']===0&&$r['estado_seguimiento']==='NUEVO'&&$r['ultima_interaccion_at']===null&&$r['proxima_accion_at']===null,'Sin fabricar verificaciones o actividad');
    check($service->previsualizar($rows,$scope,501,501)['resumen']['existentes']===3,'Reimportar detecta duplicados');
    $again=$service->confirmar($preview['filas'],$scope,501,501);check($again['insertadas']===0&&$again['omitidas_adicionales']===3,'Confirmación vuelve a comprobar duplicados');
    $repeat=$rows;$repeat[]=$rows[0];$repeat[3]['fila']=5;check($service->previsualizar($repeat,$scope,501,501)['resumen']['revisar']===1,'Duplicados en el archivo');
    echo "PASS: BD aislada, tres estados, 13 obligatorios, seis opcionales vacíos, municipios, permisos revocados/rollback, persistencia real y duplicados.\n";
}finally{$db->query("DROP DATABASE `$name`");}
