<?php
/** Prueba exclusiva de CI: usa solo una base sintética con prefijo impe_test_handly_aliados. */
if (PHP_SAPI !== 'cli' || !preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:'')) exit(2);
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/models/ConvocatoriaModel.php';
$db=(new Database())->connect(false);
function verify($ok,$what) { if(!$ok)throw new RuntimeException($what); echo "OK ".$what."\n"; }
$db->query("ALTER TABLE convocatorias
 ADD COLUMN categoria VARCHAR(30) NULL,
 ADD COLUMN tipo_convocatoria VARCHAR(40) NOT NULL DEFAULT '',
 ADD COLUMN subtipo_convocatoria VARCHAR(50) NOT NULL DEFAULT '',
 ADD COLUMN imagen VARCHAR(255) NOT NULL DEFAULT '',
 ADD COLUMN enlace_registro VARCHAR(255) NOT NULL DEFAULT '',
 ADD COLUMN creado_por INT NOT NULL DEFAULT 4,
 ADD COLUMN actualizado_por INT NOT NULL DEFAULT 4");
$db->query("CREATE TABLE convocatoria_estados (convocatoria_id INT NOT NULL, estado_id INT NOT NULL, PRIMARY KEY(convocatoria_id,estado_id)) ENGINE=InnoDB");
$manual=$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional' LIMIT 1")->fetch_assoc();
verify((int)($manual['id']??0)>0,'fixture manual existe');
$id=(int)$manual['id'];
$datos=[
 'titulo'=>'Programa Bachillerato 286', 'tipo_convocatoria'=>'bachillerato',
 'subtipo_convocatoria'=>'bachillerato-286',
 'imagen'=>'public/uploads/convocatorias/imagen_prueba_a.png',
 'enlace_registro'=>'https://example.test/registro/compartido',
 'fecha_inicio'=>date('Y-m-d'), 'fecha_termino'=>date('Y-m-d',strtotime('+30 days')),
 'estado'=>1, 'usuario_id'=>4, 'manual_id'=>$id,
 'aliado_tipo'=>'manual', 'aliado_id'=>$id, 'programa_codigo'=>'bachillerato-286'
];
$m=new ConvocatoriaModel();
$m->iniciarLote();
try {
  $a=$m->crear($datos,[1],true);
  $b=$m->crear(array_replace($datos,[
     'titulo'=>'Programa Inglés',
     'subtipo_convocatoria'=>'ingles','programa_codigo'=>'ingles',
     'imagen'=>'public/uploads/convocatorias/imagen_prueba_b.png'
  ]),[1],true);
  verify($a>0 && $b>0 && $a!==$b,'dos convocatorias creadas en un lote');
  $m->confirmarLote();
} catch (Throwable $e) { $m->cancelarLote(); throw $e; }
$ids=(int)$a.','.(int)$b;
$registros=$db->query("SELECT COUNT(DISTINCT c.id) n, COUNT(DISTINCT c.imagen) images, COUNT(DISTINCT c.enlace_registro) urls
 FROM convocatorias c WHERE c.id IN ($ids)")->fetch_assoc();
verify((int)$registros['n']===2 && (int)$registros['images']===2 &&
       (int)$registros['urls']===1,'imágenes diferentes y enlace compartido');
$links=$db->query("SELECT COUNT(*) n,COUNT(DISTINCT programa_codigo) codigos FROM marketing_aliado_convocatorias_origen WHERE convocatoria_id IN ($ids)")->fetch_assoc();
verify((int)$links['n']===2 && (int)$links['codigos']===2,'programa y origen conservados en tabla de vínculo');
$countBefore=(int)$db->query("SELECT COUNT(*) n FROM convocatorias")->fetch_assoc()['n'];
$m->iniciarLote();$aborted=false;
try {
  $first=$m->crear(array_replace($datos,['titulo'=>'Temporal no publicable']),[1],true);
  $m->crear(array_replace($datos,['titulo'=>'Fallida','manual_id'=>999999999]),[1],true);
  throw new RuntimeException('Se aceptó un origen manual inexistente');
} catch (Throwable $err) {
  $m->cancelarLote();$aborted=true;
}
verify($aborted && (int)$db->query("SELECT COUNT(*) n FROM convocatorias")->fetch_assoc()['n']===$countBefore,
  'error de segundo programa revierte el primero');
echo "PASS prueba transaccional multiprograma\n";
