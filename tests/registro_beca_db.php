<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_horario_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require __DIR__.'/../config/db_connection.php';require __DIR__.'/../app/services/RegistroEnlaceService.php';
$db=(new Database())->connect(false);$s=new RegistroEnlaceService($db);$snapshot=json_decode(file_get_contents(getenv('IMPE_BECA_SNAPSHOT')),true,32,JSON_THROW_ON_ERROR);$n=0;
$check=function($ok,$label)use(&$n){if(!$ok)throw new RuntimeException($label);echo "OK $label\n";$n++;};
foreach(['url','general','contexto'] as $key){
    $old=$snapshot[$key];$new=str_replace(RegistroEnlaceService::RUTA_ANTERIOR,RegistroEnlaceService::RUTA,$old);
    $check($s->acortar($old)===$new,'Generar nuevamente usa obtener-beca y conserva el código: '.$key);
    $check($s->acortar($new)===$new && $s->equivalentes($old,$new),'Ambas rutas son equivalentes e idempotentes: '.$key);
    $check($s->parametros($old)===$s->parametros($new),'Mismo destino, aliado y contexto: '.$key);
    $check($s->parametros($old.'?aliado=otro')===null && $s->parametros($new.'?aliado=otro')===null,'Ninguna ruta permite sustituir al aliado: '.$key);
}
$params=$s->parametros($snapshot['contexto']);$check($params['convocatoria_ref']==='beca-contexto'&&$params['estado_id']==='1','Se conserva la procedencia de la convocatoria');
$long=BASE_URL.'index.php?controller=formularioPublico&action=registro&aliado='.$snapshot['token'];
$check($s->acortar($long)===$s->acortar($snapshot['url']),'Las ligas largas recuperan el mismo código existente');
foreach(['https://evil.example/registro/obtener-beca/Ab7K9mP2xQ4Z',BASE_URL.'index.php?controller=formularioPublico&action=inscripcion'] as $bad)$check($s->acortar($bad)===$bad,'No se acortan otros dominios ni Inscripción');
$rows=$s->filas($snapshot['convocatorias']);$check(array_reduce($rows,fn($ok,$row)=>$ok&&str_contains($row['enlace_registro'],'/registro/obtener-beca/'),true),'Las ligas presentadas por el listado usan la ruta nueva');
foreach(['aliases'=>'registro_enlaces_amigables ORDER BY codigo','aliados'=>'formulario_aliados ORDER BY id','programas'=>'formulario_aliado_programas ORDER BY aliado_id,programa_codigo','convocatorias'=>'convocatorias ORDER BY id'] as $key=>$sql)$check($db->query($key==='aliases'?'SELECT codigo,HEX(destino_hash) destino_hash,parametros,created_at FROM registro_enlaces_amigables ORDER BY codigo':'SELECT * FROM '.$sql)->fetch_all(MYSQLI_ASSOC)===$snapshot[$key],'Sin cambios en datos existentes: '.$key);
echo "PASS $n comprobaciones obtener-beca · ".$db->server_info."\n";
