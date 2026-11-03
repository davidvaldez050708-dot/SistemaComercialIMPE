<?php
/** Únicamente la base ficticia recién creada por prospectos_central_db.php. */
if (PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!str_starts_with(getenv('IMPE_DB_HOST')?:'','127.0.0.1:')||!preg_match('/^impe_test_prospectos_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require __DIR__.'/../config/db_connection.php';require __DIR__.'/../app/helpers/PermissionHelper.php';require __DIR__.'/../app/services/ProspectoMetaImportador.php';
$db=(new Database())->connect(false);$repo=new ProspectoCentralRepository($db);$svc=new ProspectoMetaFormularioService($db);
function verify($b,$m){if(!$b)throw new RuntimeException($m);echo 'OK '.$m.PHP_EOL;}
function originals($db){$x=[];foreach($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$t])if($t!=='prospecto_formulario_programas')$x[$t]=$db->query('SELECT * FROM `'.$t.'`')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($x));}
function reject($fn,$msg){$rejected=false;try{$fn();}catch(DomainException $e){$rejected=true;}verify($rejected,$msg);}
$_SESSION=['usuario_id'=>1,'rol_id'=>5,'rol'=>'Marketing','permisos'=>['prospectos.ver_todos','prospectos.importar_meta']];
verify(!$svc->disponible()&&$svc->asociaciones()===[],'Configuración ausente no crea estructura durante una consulta');
$backup=sys_get_temp_dir().'/impe-cfg-'.bin2hex(random_bytes(6));mkdir($backup,0700);
$prepare=static function($mode)use($backup){
    $p=proc_open([PHP_BINARY,__DIR__.'/../tools/prospectos_formularios_programas.php','--site',dirname(__DIR__),'--backup-root',$backup,$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out];
};
$beforeSchema=originals($db);[$code,$out]=$prepare('--check');
verify($code===0&&str_contains($out,'PENDIENTE')&&!$svc->disponible()&&originals($db)===$beforeSchema,'CLI check no crea tabla ni modifica datos');
[$code,$out]=$prepare('--apply');verify($code===0&&$svc->disponible()&&originals($db)===$beforeSchema,'Preparación explícita crea únicamente la tabla vacía');
foreach(glob($backup.'/*') as $file)unlink($file);rmdir($backup);
$snapshot=originals($db);$preview=[['id'=>'f:300','nombre'=>'Nombre idéntico'],['id'=>'f:301','nombre'=>'Nombre idéntico']];
reject(fn()=>$svc->guardar('f:300','programa-a',0,$preview),'Importar no concede configurar');
$_SESSION['permisos'][]='prospectos.configurar_formularios';
reject(fn()=>$svc->guardar('f:inventado','programa-a',0,$preview),'No configurar IDs que no fueron detectados');
reject(fn()=>$svc->guardar('f:300','inventado',0,$preview),'No crear programas artificiales');
$svc->guardar('f:300','programa-a',0,$preview);$svc->guardar('f:301','programa-b',0,$preview);
$map=array_column($svc->asociaciones(),null,'formulario_id');
verify($map['f:300']['programa_codigo']==='programa-a'&&$map['f:301']['programa_codigo']==='programa-b','Mismo nombre y distintos IDs conservan sus programas independientes');
$svc->guardar('f:300','programa-a',1,$preview);verify(count($svc->historial())===2,'Guardar sin cambios no inventa versiones');
reject(fn()=>$svc->guardar('f:300','programa-b',0,$preview),'Formulario desactualizado no sobrescribe asociación confirmada');
$svc->guardar('f:300','programa-b',1,$preview);$svc->guardar('f:300','',2,$preview);$svc->guardar('f:300','programa-a',3,$preview);
verify(count($svc->historial())===5,'Cambios, retirada y restauración conservan historial');
verify(originals($db)===$snapshot,'Configurar no modifica personas, intereses, horarios, Aliados ni integraciones');
$person=$repo->crearPersona(['nombre_completo'=>'Multi campaña ficticio','telefono_original'=>'+525500000090']);
$o1=$repo->guardarOrigen($person,['tipo_origen'=>'META_ADS','sistema_origen'=>'META_ADS','tipo_registro'=>'LEAD','identificador_origen'=>'7001','datos_originales_ref'=>'meta:7001','campania_id'=>'c:7001','campania_nombre'=>'<script>campaña ficticia</script>']);
$o2=$repo->guardarOrigen($person,['tipo_origen'=>'META_ADS','sistema_origen'=>'META_ADS','tipo_registro'=>'LEAD','identificador_origen'=>'7002','datos_originales_ref'=>'meta:7002','campania_id'=>'c:7002','campania_nombre'=>str_repeat('Campaña extensa ',15).'Fin']);
$repo->interes($person,$o1,'programa-a','Programa A (prueba)',null);$repo->interes($person,$o2,'programa-b','Programa B (prueba)',null);
$a=hash('sha256','catalogo:programa-a');$b=hash('sha256','catalogo:programa-b');
$rows=$repo->listar(['origen'=>'META_ADS','campania'=>'c:7001','programa'=>$a,'buscar'=>'Multi campaña']);
verify($rows['total']===1&&count($rows['registros'][0]['campanias'])===2,'Filtrar no duplica personas ni pierde otras campañas');
verify($rows['registros'][0]['campanias'][1]['nombre']===str_repeat('Campaña extensa ',15).'Fin','Sin truncar nombres con GROUP_CONCAT');
verify($repo->listar(['origen'=>'META_ADS','campania'=>'c:7001','programa'=>$b])['total']===0,'Campaña y programa deben corresponder a la misma procedencia');
verify($repo->listar(['origen'=>'META_ADS','campania'=>"c:7001' OR 1=1 --"])['total']===0,'Filtro de campaña parametrizado');
$initial=$repo->listar([]);verify($repo->listar(['campania'=>['manipulado']])['total']===$initial['total'],'Filtro de campaña ajeno a su sección no altera Todos');
for($i=0;$i<28;$i++){$p=$repo->crearPersona(['nombre_completo'=>'Lote campaña '.$i]);$repo->guardarOrigen($p,['tipo_origen'=>'META_ADS','sistema_origen'=>'META_ADS','tipo_registro'=>'LEAD','identificador_origen'=>'800'.$i,'datos_originales_ref'=>'meta:800'.$i,'campania_id'=>'c:7001','campania_nombre'=>'Campaña ficticia']);}
$n=fn()=>(int)$db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'];
$before=$n();$repo->listar(['origen'=>'META_ADS','limite'=>1]);$one=$n()-$before;
$before=$n();$repo->listar(['origen'=>'META_ADS','limite'=>100]);$many=$n()-$before;
verify($one===$many&&$many<=6,'Número de consultas constante para una o cien filas, sin N+1');
echo "PASS campañas y asociaciones versionadas; sin escrituras fuera de la configuración durante su administración.\n";
