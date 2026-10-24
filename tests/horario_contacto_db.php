<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_horario_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require __DIR__.'/../app/models/FormularioRegistroModel.php';
require __DIR__.'/../app/models/ProspectoModel.php';
require __DIR__.'/../app/services/RegistroEnlaceService.php';
$db=(new Database())->connect(false);$n=0;
function hcheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "OK $label\n";}
$model=new FormularioRegistroModel();
$data=['nombre'=>'Ana','apellido'=>'Prueba','fecha_nacimiento'=>'1990-01-01','movil'=>'7771234567','correo'=>'legacy@example.test','perfil_interes'=>'Bachillerato General','lugar_laboras'=>'','cargo_puesto'=>'','estado_id'=>1,'municipio_id'=>1,'creado_por'=>null,'origen'=>'PUBLICO'];
$legacy=$model->guardar($data);
$old=$db->query('SELECT * FROM formulario_registros')->fetch_all(MYSQLI_ASSOC);
$without=(new ProspectoModel())->listar()['registros'];hcheck($without[0]['horario_contacto']===null,'Prospectos funciona antes de la migración');
try{$model->guardar($data+['horario_contacto'=>'Sin preferencia']);throw new RuntimeException('Descartó un horario sin columna');}catch(DomainException $e){hcheck((int)$db->query('SELECT COUNT(*) FROM formulario_registros')->fetch_row()[0]===1,'Sin columna no se descarta silenciosamente la preferencia');}
$migrate=function($mode){$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../tools/migrar_horario_contacto.php').' '.$mode;exec($cmd,$out,$code);hcheck($code===0,'Migración '.$mode);return implode("\n",$out);};
hcheck(str_contains($migrate('--check'),'PENDIENTE')&&!$db->query("SHOW COLUMNS FROM formulario_registros LIKE 'horario_contacto'")->num_rows,'Check de sólo lectura');
$aliasesBefore=$db->query('SELECT * FROM registro_enlaces_amigables')->fetch_all(MYSQLI_ASSOC);
$tokens=$db->query('SELECT id,token FROM formulario_aliados ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$migrate('--apply');$migrate('--apply');
$after=$db->query('SELECT * FROM formulario_registros')->fetch_all(MYSQLI_ASSOC);$null=$after[0]['horario_contacto'];unset($after[0]['horario_contacto']);
hcheck($after===$old&&$null===null,'Migración idempotente no altera los registros históricos');
$custom=HorarioContacto::desdeEntrada(['contacto_disponibilidad'=>'personalizado','contacto_dias'=>['lun','vie'],'contacto_desde'=>'17:30','contacto_hasta'=>'20:00']);
foreach(['PUBLICO','MARKETING'] as $origin){$d=$data;$d['origen']=$origin;$d['correo']=strtolower($origin).'@example.test';$d['horario_contacto']=$custom;$id=$model->guardar($d);hcheck($db->query('SELECT horario_contacto FROM formulario_registros WHERE id='.$id)->fetch_row()[0]===$custom,'Modelo conserva horario '.$origin);}
$token=$tokens[0]['token'];$d=$data;$d['aliado_token']=$token;$d['correo']='ally@example.test';$d['horario_contacto']='Sin preferencia';$id=$model->guardar($d);$again=$model->guardar($d);
$p=$db->query("SELECT * FROM formulario_aliado_procedencias WHERE tipo='registro' AND registro_id=$id")->fetch_assoc();
hcheck($id===$again&&$p['programa_codigo']==='bachillerato-general'&&(int)$p['aliado_id']===(int)$tokens[0]['id'],'Registro del aliado mantiene programa, procedencia y reenvío idempotente');
$rows=(new ProspectoModel())->listar(1,100,['buscar'=>'ally@example.test'])['registros'];hcheck(count($rows)===1&&$rows[0]['horario_contacto']==='Sin preferencia','Prospectos devuelve horario guardado con filtro de correo');
$d['origen']='PUBLICO_INSCRIPCION';$model->guardar($d);hcheck(!$db->query("SHOW COLUMNS FROM formulario_inscripciones LIKE 'horario_contacto'")->num_rows,'Inscripción no recibe columna ni cambia su almacenamiento');
hcheck($db->query('SELECT id,token FROM formulario_aliados ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$tokens&&$db->query('SELECT * FROM registro_enlaces_amigables')->fetch_all(MYSQLI_ASSOC)===$aliasesBefore,'Migración y guardado no cambian tokens ni enlaces preexistentes');
echo "PASS $n comprobaciones SQL horario · ".$db->server_info."\n";
