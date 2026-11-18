<?php
/** MariaDB sintética exclusivamente; exporta vistas para las pruebas de navegador. */
if(PHP_SAPI!=='cli' || getenv('IMPE_SUPERVISION_TEST')!=='YES')exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../app/services/EmbudoComercialRepository.php';
require_once __DIR__.'/../app/services/EmbudoComercialV3SeguimientoService.php';
putenv('IMPE_BASE_URL=http://supervision-v3.test/');
require_once __DIR__.'/../config/config.php';
function comprobarSupervision(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function rechazarSupervision(callable $fn): void {try{$fn();}catch(DomainException $e){return;}throw new RuntimeException('Acceso indebido aceptado');}
function sesionSupervision(bool $supervisor=true): void {
    $_SESSION=['usuario_id'=>$supervisor?1:22,'rol_id'=>$supervisor?1:3,'permisos'=>[
        'ventas.embudo.ver','ventas.embudo.mover','ventas.recuperacion.ver','ventas.descartados.ver',
        'ventas.recuperacion.gestionar','ventas.descartados.gestionar','telefonia.usar','telefonia.salientes',
        ...($supervisor?['ventas.embudo.supervisar']:[])
    ]];
}
function renderSupervision(string $file,array $vars): string {
    extract($vars,EXTR_SKIP);ob_start();require __DIR__.'/../app/views/embudo_comercial/'.$file.'.php';return ob_get_clean();
}
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'',(int)getenv('MYSQL_PORT'));
$db->set_charset('utf8mb4');$nombre='impe_supervision_test_'.bin2hex(random_bytes(5));
$db->query("CREATE DATABASE $nombre CHARACTER SET utf8mb4");$db->select_db($nombre);
try{
 foreach([
  'usuarios(id INT PRIMARY KEY,nombre VARCHAR(90),apellidos VARCHAR(90))',
  'prospectos_comerciales(id BIGINT UNSIGNED PRIMARY KEY,nombre_completo VARCHAR(255),telefono_original VARCHAR(40),telefono_normalizado VARCHAR(40),correo VARCHAR(190),pais_nombre VARCHAR(50),estado_provincia VARCHAR(50),ciudad VARCHAR(50))',
  'prospecto_asignaciones(id BIGINT UNSIGNED PRIMARY KEY,prospecto_id BIGINT UNSIGNED,asesor_id INT,vigente TINYINT NULL,asignado_at DATETIME,fecha_trabajo DATE,proxima_accion_at DATETIME,finalizado_at DATETIME)',
  'prospecto_embudo_etapas(asignacion_id BIGINT UNSIGNED PRIMARY KEY,etapa VARCHAR(40),cambiado_por INT,actualizado_at DATETIME)',
  'prospecto_embudo_agenda(asignacion_id BIGINT UNSIGNED PRIMARY KEY,proxima_accion_at DATETIME,revision_fecha DATE,actualizado_at DATETIME,actualizado_por INT)',
  'prospecto_origenes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,prospecto_id BIGINT UNSIGNED,origen_padre_id BIGINT UNSIGNED NULL,tipo_origen VARCHAR(30),aliado_nombre VARCHAR(90),formulario_nombre VARCHAR(90),fecha_registro_original DATETIME)',
  'prospecto_intereses(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,prospecto_id BIGINT UNSIGNED,programa_codigo VARCHAR(80),nombre_original VARCHAR(120))',
  'prospecto_preferencias_contacto(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,origen_id BIGINT UNSIGNED,valor_original VARCHAR(120),zona_horaria VARCHAR(80),interpretacion_estado VARCHAR(40))',
  'prospecto_embudo_movimientos(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,asignacion_id BIGINT UNSIGNED,etapa_anterior VARCHAR(40),etapa_nueva VARCHAR(40),motivo VARCHAR(190),usuario_id INT,creado_at DATETIME)',
  'prospecto_embudo_llamadas(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,asignacion_id BIGINT UNSIGNED,usuario_id INT,extension VARCHAR(20),pbx_call_id VARCHAR(100),resultado VARCHAR(36),creado_at DATETIME)',
  'telefonia_ventas_llamadas(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT,extension VARCHAR(20),pbx_call_id VARCHAR(100),fecha DATETIME,direccion VARCHAR(12))',
  'telefonia_ventas_resultados(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT,extension VARCHAR(20),pbx_call_id VARCHAR(100),resultado VARCHAR(36),observacion VARCHAR(190))',
  'telefonia_ventas_resultado_detalles(resultado_id BIGINT UNSIGNED PRIMARY KEY,catalogo_version VARCHAR(10),contacto_antes_corte VARCHAR(2))',
  'telefonia_ventas_resultado_selecciones(resultado_id BIGINT UNSIGNED,codigo VARCHAR(36),posicion INT)',
  'prospecto_embudo_inscripciones_v3(asignacion_id BIGINT UNSIGNED PRIMARY KEY,formulario_inscripcion_id BIGINT UNSIGNED,verificado_at DATETIME,vinculado_por INT)',
  'formulario_inscripciones(id BIGINT UNSIGNED PRIMARY KEY,created_at DATETIME)'
 ] as $tabla)$db->query("CREATE TABLE $tabla ENGINE=InnoDB");
 foreach(['2026_11_11_ventas_subetapas_comerciales_propuesta.sql','2026_11_12_ventas_seguimiento_operativo_v3.sql','2026_11_18_ventas_tipo_actividad_v3.sql'] as $archivo){
  $sql=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/migrations/'.$archivo));
  foreach(explode(';',$sql) as $parte)if(trim($parte)!=='')$db->query($parte);
 }
 $db->query("INSERT INTO usuarios VALUES(1,'Administrador',''),(22,'Asesor <b>22</b>','Prueba'),(23,'Asesor 23','Prueba'),(24,'Asesor histórico','Prueba')");
 $datos=[[1,'NUEVO',22],[2,'CONTACTADO',22],[3,'POTENCIAL',22],[4,'SEGUIMIENTO',22],[5,'PROCESO_INSCRIPCION',22],[6,'INTERESADO',23],[7,'NUEVO',23],[8,'RECUPERACION',22],[9,'DESCARTADO',23],[10,'PROCESO_INSCRIPCION',22],[11,'PROCESO_INSCRIPCION',24],[12,'NUEVO',22],[13,'PAGO_CONFIRMADO',23]];
 for($i=100;$i<=120;$i++)$datos[]=[$i,'NUEVO',23];
 foreach($datos as [$id,$etapa,$asesor]){
  $db->query("INSERT INTO prospectos_comerciales VALUES($id,'Prospecto sintético $id','5500000001','+525500000001','prueba$id@example.test','México','Morelos','Cuernavaca')");
  $vigente=in_array($id,[10,11],true)?'NULL':($id===12?'0':'1');
  $final=in_array($id,[10,11],true)?"'2026-11-17 18:00:00'":'NULL';
  $db->query("INSERT INTO prospecto_asignaciones VALUES($id,$id,$asesor,$vigente,'2026-11-13 16:00:00','2026-11-13',NULL,$final)");
  $db->query("INSERT INTO prospecto_embudo_etapas VALUES($id,'$etapa',22,UTC_TIMESTAMP())");
  $db->query("INSERT INTO prospecto_origenes(prospecto_id,tipo_origen,formulario_nombre,fecha_registro_original) VALUES($id,'META_ADS','Formulario sintético',UTC_TIMESTAMP())");
  $db->query("INSERT INTO prospecto_intereses(prospecto_id,programa_codigo,nombre_original) VALUES($id,'titulacion-experiencia-laboral','Titulación por experiencia laboral')");
 }
 $db->query("INSERT INTO prospecto_embudo_subetapas VALUES(4,'ESPERANDO_DOCUMENTOS',22,UTC_TIMESTAMP(),1)");
 $db->query("INSERT INTO prospecto_embudo_subetapas_historial(asignacion_id,etapa_en_evento,subetapa_nueva,motivo,origen,usuario_id,creado_at) VALUES(4,'SEGUIMIENTO','ESPERANDO_DOCUMENTOS','Prueba de subetapa.','ASESOR_V3',22,UTC_TIMESTAMP())");
 $db->query("INSERT INTO prospecto_embudo_movimientos(asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at) VALUES(4,'POTENCIAL','SEGUIMIENTO','Prueba de movimiento.',22,UTC_TIMESTAMP())");
 $db->query("INSERT INTO prospecto_embudo_actividad_v3(asignacion_id,usuario_id,etapa,accion,motivo,fecha_pendiente,creado_at) VALUES(4,22,'SEGUIMIENTO','PROGRAMAR','Prueba de actividad.','2099-01-01 16:00:00',UTC_TIMESTAMP())");
 $db->query("UPDATE prospecto_embudo_actividad_v3 SET tipo_actividad='DOCUMENTOS',detalle_actividad='Revisar certificado <prueba>.' WHERE asignacion_id=4");
 $db->query("INSERT INTO prospecto_embudo_llamadas(asignacion_id,usuario_id,extension,pbx_call_id,resultado,creado_at) VALUES(4,22,'157','out_supervision_1','CONVERSACION_PERSONA',UTC_TIMESTAMP()),(4,23,'158','out_ajena_1','SIN_RESPUESTA',UTC_TIMESTAMP())");
 $db->query("INSERT INTO telefonia_ventas_resultados(usuario_id,extension,pbx_call_id,resultado,observacion) VALUES(22,'157','out_supervision_1','CONVERSACION_PERSONA','Prueba de llamada.')");
 foreach([10,11] as $id){
  $db->query("INSERT INTO formulario_inscripciones VALUES($id,UTC_TIMESTAMP())");
  $db->query("INSERT INTO prospecto_embudo_inscripciones_v3 VALUES($id,$id,UTC_TIMESTAMP(),22)");
 }
 // Una actividad completada o futura no hereda el vencimiento de la agenda anterior.
 $db->query("INSERT INTO prospecto_embudo_actividad_v3(asignacion_id,usuario_id,etapa,accion,motivo,completada_at,creado_at) VALUES(3,22,'POTENCIAL','COMPLETAR','Actividad completada de prueba.',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $db->query("UPDATE prospecto_embudo_agenda SET proxima_accion_at='2000-01-01 16:00:00' WHERE asignacion_id IN (1,3,4)");
 $escrituras=static function()use($db){$rows=$db->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete','Com_create_table','Com_alter_table')")->fetch_all(MYSQLI_ASSOC);return array_column($rows,'Value','Variable_name');};
 $antes=$escrituras();$repo=new EmbudoComercialRepository($db);sesionSupervision();
 require __DIR__.'/notificacion_destino_db.php';
 $proc=proc_open(['py',__DIR__.'/notificacion_destino_http.py',$nombre],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 comprobarSupervision(is_resource($proc),'iniciar HTTP de localización');
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 comprobarSupervision(proc_close($proc)===0,'HTTP de destino: '.$out.$err);echo $out;

 $f=EmbudoComercialRepository::filtros([],true);$pag=EmbudoComercialRepository::paginasV3Operativo([]);
 $todos=$repo->listadoV3Operativo(1,$f,$pag,true);
 comprobarSupervision($todos['columnas']['POTENCIAL']['total']===2 && $todos['columnas']['NUEVO']['total']===23,'mismas etapas y totales multi asesor');
 comprobarSupervision($todos['columnas']['NUEVO']['paginas']===2 && count($todos['columnas']['NUEVO']['registros'])===20,'paginación independiente');
 $f22=EmbudoComercialRepository::filtros(['asesor'=>'22'],true);
 $tablero=$repo->listadoV3Operativo(1,$f22,$pag,true);
 comprobarSupervision($tablero['total_activos']===5 && $tablero['externos']['RECUPERACION']===1,'filtro asesor incluye etapas correctas y excluye cierres');
 comprobarSupervision($tablero['columnas']['SEGUIMIENTO']['registros'][0]['actividad_pendiente']==='2099-01-01 16:00:00','supervisión ve agenda V3');
 comprobarSupervision(count($repo->expedientesCerrados(1,1,true))===2 && count($repo->expedientesCerrados(1,1,true,22))===1,'cierres supervisados filtrables');
 $opciones=$repo->opciones(1,true,true);comprobarSupervision(in_array(24,array_map('intval',array_column($opciones['asesores'],'id')),true),'asesor solo histórico disponible');
 foreach(['resumen','actividad','llamadas'] as $panel){
  $detalle=$repo->detalle(1,true,4,'embudo',true,$panel);
  comprobarSupervision($detalle!==null && $detalle['asesor_id']==22,'ficha supervisada autorizada');
  if($panel==='actividad')comprobarSupervision(count($detalle['historial_actividades'])===1 && count($detalle['historial_subetapas'])===1 && count($detalle['historial_movimientos'])===1,'historial completo V3');
  if($panel==='llamadas')comprobarSupervision(count($detalle['llamadas_expediente'])===1 && $detalle['llamadas_expediente'][0]['extension']==='157','llamadas solo propietario de la asignación');
  if($panel==='resumen')comprobarSupervision($detalle['proxima_accion_at']==='2099-01-01 16:00:00','resumen usa cita V3');
 }
 comprobarSupervision($repo->detalle(1,true,10,'embudo',true)!==null,'expediente cerrado accesible por supervisión');
 $vars=['supervisor'=>true,'usuarioId'=>1,'error'=>'','disponible'=>true,'opciones'=>$opciones,'tablero'=>$tablero,'filtros'=>$f22,'paginasV3'=>$pag,'vista'=>'embudo'];
 $admin=renderSupervision('operativo_v3',$vars);
 foreach(['Potenciales','Proceso Inscripción','Expedientes cerrados','Expediente comercial','Esperando documentos','name="asesor"','Asesor &lt;b&gt;22&lt;/b&gt;'] as $texto)comprobarSupervision(str_contains($admin,$texto),'render supervisor: '.$texto);
 foreach(['data-embudo-marcar=','data-v3-mover ',"data-v3-actividad\n",'data-v3i-iniciar','draggable="true"'] as $prohibido)comprobarSupervision(!str_contains($admin,$prohibido),'supervisor no opera: '.$prohibido);
 $fixtures=['admin.html'=>$admin];
 $fixtures['todos.html']=renderSupervision('operativo_v3',array_replace($vars,['filtros'=>$f,'tablero'=>$todos]));
 $f23=EmbudoComercialRepository::filtros(['asesor'=>'23'],true);
 $fixtures['admin23.html']=renderSupervision('operativo_v3',array_replace($vars,['filtros'=>$f23,'tablero'=>$repo->listadoV3Operativo(1,$f23,$pag,true)]));
 $pag2=EmbudoComercialRepository::paginasV3Operativo(['p3_nuevo'=>'2']);
 $fixtures['admin23pagina2.html']=renderSupervision('operativo_v3',array_replace($vars,['filtros'=>$f23,'paginasV3'=>$pag2,'tablero'=>$repo->listadoV3Operativo(1,$f23,$pag2,true)]));
 foreach(['recuperacion','descartados'] as $vista){
  $filtro=$vista==='recuperacion'?$f22:$f23;$cols=$repo->listado(1,true,$filtro,$vista,true);
  $html=renderSupervision('bandejas_v3',array_replace($vars,['vista'=>$vista,'filtros'=>$filtro,'tablero'=>$cols]));
  comprobarSupervision(str_contains($html,'Expedientes cerrados') && str_contains($html,'name="asesor"') && !str_contains($html,'data-v3b-llamar='),'bandeja comparte consulta y navegación');
  $fixtures[$vista.'.html']=$html;
  $fixtures[$vista.'-todos.html']=renderSupervision('bandejas_v3',array_replace($vars,[
      'vista'=>$vista,'filtros'=>$f,'tablero'=>$repo->listado(1,true,$f,$vista,true)
  ]));
 }
 $fixtures['cerrados.html']=renderSupervision('expedientes',array_replace($vars,['filtros'=>$f,'expedientes'=>$repo->expedientesCerrados(1,1,true),'pagina'=>1]));
 $fixtures['cerrados22.html']=renderSupervision('expedientes',$vars+['expedientes'=>$repo->expedientesCerrados(1,1,true,22),'pagina'=>1]);
 foreach(['resumen','actividad','llamadas'] as $panel){
  $prospecto=$repo->detalle(1,true,4,'embudo',true,$panel);
  $fixtures[$panel.'.html']=renderSupervision('detalle',$vars+['prospecto'=>$prospecto,'operativoV3'=>true,'panel'=>$panel,'paginas'=>[]]);
 }
 comprobarSupervision(!str_contains($fixtures['llamadas.html'],'<details data-expediente-grabacion='),'supervisión no amplía permisos de audio');
 rechazarSupervision(fn()=>(new EmbudoComercialV3SeguimientoService($db))->registrar(1,4,'SEGUIMIENTO',1,'REGISTRAR','Intento de operación.','',''));
 sesionSupervision(false);$propios=$repo->listadoV3Operativo(22,$f,$pag);
 comprobarSupervision($propios['total_activos']===5,'asesor mantiene propiedad');
 $fVencidos=$f;$fVencidos['vencidos']=true;$soloVencidos=$repo->listadoV3Operativo(22,$fVencidos,$pag);
 $idsVencidos=[];foreach($soloVencidos['columnas'] as $columna)foreach($columna['registros'] as $r)$idsVencidos[]=(int)$r['asignacion_id'];
 comprobarSupervision(in_array(1,$idsVencidos,true) && !in_array(3,$idsVencidos,true) && !in_array(4,$idsVencidos,true),'vencidos respeta actividad completada o futura y conserva agenda sin ciclo V3');
 comprobarSupervision($repo->detalle(22,false,6,'embudo',true)===null,'asesor no accede a expediente ajeno');
 rechazarSupervision(fn()=>$repo->listadoV3Operativo(22,$f,$pag,true));
 rechazarSupervision(fn()=>$repo->detalle(22,true,6,'embudo',true));
 rechazarSupervision(fn()=>$repo->expedientesCerrados(22,1,true));
 comprobarSupervision(count($repo->expedientesCerrados(22,1))===1,'cierres propios');
 $fixtures['asesor.html']=renderSupervision('operativo_v3',array_replace($vars,['supervisor'=>false,'usuarioId'=>22,'tablero'=>$propios]));
 $doc=new DOMDocument();@$doc->loadHTML($fixtures['asesor.html'],LIBXML_NONET);$xp=new DOMXPath($doc);
 foreach([1=>[false,false],2=>[false,false],3=>[true,true],4=>[false,true],5=>[false,true]] as $id=>[$avance,$inscripcion]){
  $base="//article[@data-asignacion-id='$id']/div[@class='impe-v3o-acciones']";
  comprobarSupervision($xp->query($base.'/button[@data-v3-actividad]')->length===1,'actividad directa en etapa '.$id);
  comprobarSupervision($xp->query($base.'/button[@data-v3-gestionar]')->length===($avance?1:0),'avance contextual '.$id);
  comprobarSupervision($xp->query($base.'/button[@data-v3i-iniciar]')->length===($inscripcion?1:0),'inscripción contextual '.$id);
  comprobarSupervision($xp->query($base.'/button[@data-v3-whatsapp-reservado and @disabled]')->length===1,'WhatsApp reservado sin acción '.$id);
  comprobarSupervision($xp->query($base.'/div/button[@data-v3-actividad or @data-v3-gestionar or @data-v3i-iniciar]')->length===0,'sin acciones frecuentes duplicadas en Más');
 }
 comprobarSupervision($xp->query("//a[@aria-label='Ver expediente comercial']")->length===0,'asesor conserva expediente solo desde el nombre');
 $sinContacto=$propios;
 foreach($sinContacto['columnas']['SEGUIMIENTO']['registros'] as &$item)$item['subetapa']='NO_CONTACTAR';unset($item);
 $fixtures['asesor-no-contactar.html']=renderSupervision('operativo_v3',array_replace($vars,['supervisor'=>false,'usuarioId'=>22,'tablero'=>$sinContacto]));
 @$doc->loadHTML($fixtures['asesor-no-contactar.html'],LIBXML_NONET);$xp=new DOMXPath($doc);
 comprobarSupervision($xp->query("//article[@data-asignacion-id='4']//button[@data-embudo-marcar or @data-v3-whatsapp-reservado or @data-v3-actividad or @data-v3i-iniciar]")->length===0,'No contactar sin accesos operativos');
 $_SESSION['permisos']=array_values(array_diff($_SESSION['permisos'],['ventas.embudo.mover']));
 $fixtures['asesor-consulta.html']=renderSupervision('operativo_v3',array_replace($vars,['supervisor'=>false,'usuarioId'=>22,'tablero'=>$propios]));
 @$doc->loadHTML($fixtures['asesor-consulta.html'],LIBXML_NONET);$xp=new DOMXPath($doc);
 comprobarSupervision($xp->query('//button[@data-v3-actividad or @data-v3-gestionar or @data-v3i-iniciar]')->length===0,'sin permisos no aparecen operaciones');
 sesionSupervision(false);$_SESSION['usuario_id']=23;
 $asesor23=$repo->listadoV3Operativo(23,$f,$pag);
 $fixtures['asesor23.html']=renderSupervision('operativo_v3',array_replace($vars,['supervisor'=>false,'usuarioId'=>23,'tablero'=>$asesor23]));
 @$doc->loadHTML($fixtures['asesor23.html'],LIBXML_NONET);$xp=new DOMXPath($doc);
 comprobarSupervision($xp->query("//article[@data-asignacion-id='6']/div[@class='impe-v3o-acciones']/button[@data-v3-gestionar or @data-v3i-iniciar]")->length===2,'INTERESADO mantiene acciones de Potenciales');
 comprobarSupervision($xp->query("//article[@data-asignacion-id='13']//button[@data-v3-gestionar or @data-v3-actividad or @data-v3i-iniciar or @data-v3-whatsapp-reservado]")->length===0,'históricos sin operaciones V3 adicionales');
 sesionSupervision(false);
 // Validate generated links through the real parser, with and without an advisor.
 foreach($fixtures as $archivo=>$html){
  preg_match_all('/href="([^"]+)"/',$html,$links);
  foreach($links[1] as $href){
   $query=parse_url(html_entity_decode($href,ENT_QUOTES,'UTF-8'),PHP_URL_QUERY);
   if(!is_string($query))continue;
   parse_str($query,$get);
   if(($get['controller']??'')!=='embudoComercial')continue;
   comprobarSupervision(!isset($get['asesor']) || $get['asesor']!=='0','enlace sin asesor cero: '.$archivo);
   EmbudoComercialRepository::filtros($get,true);
  }
 }
 $_SESSION=['usuario_id'=>1,'rol_id'=>1,'permisos'=>[]];rechazarSupervision(fn()=>$repo->listadoV3Operativo(1,$f,$pag,true));
 $sitio=sys_get_temp_dir().'/impe_supervision_site_'.bin2hex(random_bytes(5));
 mkdir($sitio,0700);mkdir($sitio.'/config',0700);
 $conexion='<?php class Database {public function connect($crear=false){return new mysqli("127.0.0.1",getenv("MYSQL_USER"),getenv("MYSQL_PASSWORD"),'.var_export($nombre,true).',(int)getenv("MYSQL_PORT"));}}';
 file_put_contents($sitio.'/config/db_connection.php',$conexion);
 try{
  $codigo='import sys;sys.path.insert(0,sys.argv[1]);import publicar_supervision_comercial_v3_hostinger as p;p.comprobar_esquema(sys.argv[2])';
  $proceso=proc_open([PHP_OS_FAMILY==='Windows'?'py':'python3','-c',$codigo,realpath(__DIR__.'/../tools'),$sitio],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  comprobarSupervision(is_resource($proceso),'iniciar preflight de metadatos');
  $salida=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  comprobarSupervision(proc_close($proceso)===0 && $error==='' && str_contains($salida,'ESQUEMA_SUPERVISION_OK'),'preflight sobre esquema sintético: '.$error);
 }finally{unlink($sitio.'/config/db_connection.php');rmdir($sitio.'/config');rmdir($sitio);}
 comprobarSupervision($antes===$escrituras(),'todas las consultas son de solo lectura');
 if($dir=getenv('IMPE_SUPERVISION_RENDER_DIR')){
  if(!is_dir($dir))mkdir($dir,0700,true);
  foreach($fixtures as $file=>$html){
   $idFixture=str_starts_with($file,'asesor23')?23:(str_starts_with($file,'asesor')?22:1);
   file_put_contents($dir.'/'.$file,'<!doctype html><meta charset="utf-8"><script>window.IMPE_CURRENT_USER_ID='.$idFixture.';</script>'.$html);
  }
 }
 if(getenv('IMPE_TIPOS_PUBLICACION_TEST')==='YES'){
  $proceso=proc_open(['py',__DIR__.'/tipos_actividad_publicacion_db.py',$nombre],
   [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  comprobarSupervision(is_resource($proceso),'iniciar prueba de entrega con base aislada');fclose($pipes[0]);
  $salida=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  comprobarSupervision(proc_close($proceso)===0,'entrega aislada: '.$salida.$error);echo $salida;
 }
 if(getenv('IMPE_RECORDATORIOS_HOST_COMPAT_TEST')==='YES'){
  $proceso=proc_open(['py',__DIR__.'/publicar_recordatorios_hostinger_compat_test.py',$nombre],
   [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  comprobarSupervision(is_resource($proceso),'iniciar compatibilidad Hostinger con esquema aislado');fclose($pipes[0]);
  $salida=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  comprobarSupervision(proc_close($proceso)===0,'compatibilidad Hostinger: '.$salida.$error);echo $salida;
 }
 if(getenv('IMPE_DESTINO_PUBLICACION_TEST')==='YES'){
  $proc=proc_open(['py',__DIR__.'/publicar_notificacion_destino_test.py',$nombre],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  comprobarSupervision(is_resource($proc),'iniciar entrega de localización');
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  comprobarSupervision(proc_close($proc)===0,'entrega de destino: '.$out.$err);echo $out;
 }
 echo "CHECK_OK supervisión: mismas etapas, tarjetas, agenda V3, asesor, cierres, historial, permisos, aislamiento y cero escrituras.\n";
}finally{$db->query("DROP DATABASE $nombre");$db->close();}
