<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_v4_v5_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';require_once __DIR__.'/../app/services/AliadoRegistroService.php';require_once __DIR__.'/../app/models/ConvocatoriaModel.php';
$db=(new Database())->connect(false);$s=new RegistroEnlaceService($db);$checks=0;
$check=static function($v,$m)use(&$checks){if(!$v)throw new RuntimeException($m);echo "OK $m\n";$checks++;};
$tokens=$db->query('SELECT id,token FROM formulario_aliados ORDER BY id')->fetch_all(MYSQLI_ASSOC);$old=BASE_URL.'index.php?controller=formularioPublico&action=registro';
$url=$s->acortar($old);$check((bool)preg_match('#/registro/obtener-beca/[A-Za-z0-9_-]{12}$#D',$url),'ruta nueva y código corto de 12 caracteres');
$code=basename($url);$check($s->resolver($code)===['action'=>'registro','controller'=>'formularioPublico'],'Registro general conserva destino');$check($s->acortar($url)===$url&&$s->acortar($old)===$url,'alias estable e idempotente');
$token=$db->query('SELECT a.token FROM formulario_aliados a JOIN formulario_aliado_registro r ON r.aliado_id=a.id WHERE r.seguimiento_id=10')->fetch_row()[0];
$ally=$old.'&aliado='.$token;$short=$s->acortar($ally);$check($s->resolver(basename($short))['aliado']===$token,'alias conserva token del aliado');$check($s->equivalentes($ally,$short),'liga larga y amigable son equivalentes');
$manual=$tokens[0]['token'];$check($s->acortar($old.'&aliado='.$manual)!==$short||$manual===$token,'aliados distintos no comparten alias');
$context=$ally.'&convocatoria_ref=ref-sintetica&estado_id=1&convocatoria=bachillerato%7Cbachillerato-general&titulo=Prueba';$contextShort=$s->acortar($context);$params=$s->resolver(basename($contextShort));
$check($params['convocatoria_ref']==='ref-sintetica'&&$params['estado_id']==='1'&&$params['titulo']==='Prueba','procedencia y clasificación conservadas');$check(!$s->equivalentes($short,$contextShort),'procedencias diferentes no se mezclan');
$check($s->acortar($old.'&titulo=Prueba&estado_id=1&convocatoria_ref=ref-sintetica&aliado='.$token.'&convocatoria=bachillerato%7Cbachillerato-general')===$contextShort,'orden de parámetros no duplica alias');
$check($s->resolver(basename($s->acortar($url.'?estado_id=1&titulo=Contexto')) )['titulo']==='Contexto','generador general puede agregar contexto y volver a acortar');
foreach(['https://evil.example/index.php?controller=formularioPublico&action=registro',BASE_URL.'index.php?controller=formularioPublico&action=inscripcion',BASE_URL.'index.php?controller=usuario&action=eliminar',BASE_URL.'index.php?controller=formularioPublico&action=guardarRegistro',$old.'&aliado[]=x'] as $bad)$check($s->acortar($bad)===$bad,'no acorta destinos ajenos, acciones o parámetros no válidos');
$check($s->parametros($short.'?aliado=otro')===null,'query no sustituye identidad del alias');
try{$s->resolver('invalido');throw new RuntimeException('Aceptó código inválido');}catch(DomainException $e){$check($e->getCode()===404,'código inválido responde no encontrado');}
try{$s->resolver('000000000000');throw new RuntimeException('Aceptó código inexistente');}catch(DomainException $e){$check($e->getCode()===404,'código inexistente responde no encontrado');}
$_SESSION=['usuario_id'=>2,'rol_id'=>6,'rol'=>'Cuenta Clave','permisos'=>['convenios.ver','convocatorias.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces']];
$link=(new AliadoRegistroService($db))->enlace('institucional',10,'generar')['url'];$check($link===$short,'Cuenta Clave usa el alias del mismo aliado');
$original=$db->query("SELECT c.id,c.enlace_registro FROM convocatorias c JOIN marketing_aliado_convocatorias_origen o ON o.convocatoria_id=c.id WHERE o.origen_tipo='institucional' AND o.origen_id=10 AND c.enlace_registro LIKE '%aliado=%' LIMIT 1")->fetch_assoc();
if($original){$model=new ConvocatoriaModel();$record=$model->buscarPorId((int)$original['id']);$data=$record;$data['usuario_id']=2;$data['enlace_registro']=$s->acortar($record['enlace_registro']);$check($model->actualizar((int)$record['id'],$data,$record['estados_ids']),'editar admite alias equivalente');$saved=$db->query('SELECT enlace_registro FROM convocatorias WHERE id='.(int)$record['id'])->fetch_row()[0];$check($saved===$original['enlace_registro'],'edición conserva enlace histórico almacenado');$data['enlace_registro']=$contextShort;$check(!$model->actualizar((int)$record['id'],$data,$record['estados_ids']),'editar no sustituye enlace por otro contexto válido');}
$check($db->query('SELECT id,token FROM formulario_aliados ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$tokens,'ningún token fue alterado');
echo "PASS $checks comprobaciones de enlaces amigables · ".$db->server_info."\n";
