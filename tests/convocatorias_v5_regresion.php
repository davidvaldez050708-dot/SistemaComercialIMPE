<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_v4_v5_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../app/models/ConvocatoriaModel.php';
$m=new ConvocatoriaModel();$db=$m->conexionLote();$n=0;$check=static function($v,$s)use(&$n){if(!$v)throw new RuntimeException($s);$n++;echo "OK $s\n";};
$r=$m->obtenerListado('Convocatoria territorial histórica',1,'','','bachillerato')[0];$before=$m->buscarPorId((int)$r['id']);$data=array_merge($before,['usuario_id'=>4,'enlace_registro'=>'https://example.test/territorial-editado']);
$total=(int)$db->query('SELECT COUNT(*) FROM convocatorias')->fetch_row()[0];
$check($m->actualizar((int)$r['id'],$data,[1]),'publicación territorial mantiene edición histórica de su enlace');
$saved=$m->buscarPorId((int)$r['id']);$check($saved['enlace_registro']===$data['enlace_registro']&&$saved['imagen']===$before['imagen'],'enlace territorial persiste sin reemplazar imagen');
$check((int)$db->query('SELECT COUNT(*) FROM convocatorias')->fetch_row()[0]===$total,'editar no crea publicaciones nuevas');
$data['enlace_registro']=$before['enlace_registro'];$m->actualizar((int)$r['id'],$data,[1]);
$db->begin_transaction();
try{
    $db->query('UPDATE seguimientos_vinculacion SET activo=0 WHERE id=10');
    $fila=$m->obtenerListado('Título sin nombre institucional',1,'','','bachillerato')[0];
    $check($fila['aliado']['nombre']==='IQJ'&&$fila['aliado']['estado']==='verificado','histórico conserva aliado auténtico aunque esté desactivado');
}finally{$db->rollback();}
$check(count($m->obtenerListado('titulacion ejecutiva',2,'','','titulacion'))===1,'búsqueda por programa sin acentos');
echo "PASS $n regresiones directamente afectadas\n";
