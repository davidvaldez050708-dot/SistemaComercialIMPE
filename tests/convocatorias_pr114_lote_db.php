<?php
// Sólo esquema sintético y modelo histórico generado; nunca producción.
if (PHP_SAPI!=='cli' || !preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:'')) exit(2);
require_once __DIR__.'/../config/db_connection.php';
$path=getenv('IMPE_PR114_MODEL');
if (!$path || !is_file($path)) throw new RuntimeException('Falta modelo conciliado para la prueba.');
require_once $path;
$db=(new Database())->connect(false);$observer=(new Database())->connect(false);
$m=new ConvocatoriaModel($db);$checks=0;
function transactionCheck($ok,$label) {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "OK $label\n";}
$db->query('CREATE TABLE IF NOT EXISTS formulario_aliado_convocatorias (convocatoria_id INT PRIMARY KEY, aliado_id BIGINT UNSIGNED, programa_codigo VARCHAR(80), formulario VARCHAR(20), referencia VARCHAR(32), convenio_seguimiento_id INT NULL) ENGINE=InnoDB');
$manual=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional' LIMIT 1")->fetch_assoc()['id'];
$ally=(int)$db->query("SELECT id FROM formulario_aliados WHERE slug='handly-lenguaje-center'")->fetch_assoc()['id'];
$data=['titulo'=>'Internacional callback conservado','tipo_convocatoria'=>'bachillerato','subtipo_convocatoria'=>'bachillerato-286','imagen'=>'imagen-prueba.png','enlace_registro'=>'https://example.test/registro','fecha_inicio'=>date('Y-m-d'),'fecha_termino'=>date('Y-m-d',strtotime('+30 days')),'estado'=>1,'usuario_id'=>4];
$count=static fn($conn,$table)=>(int)$conn->query('SELECT COUNT(*) n FROM '.$table)->fetch_assoc()['n'];
$baseline=$count($db,'convocatorias');$callbackCalls=0;
$id=$m->crear($data,[1],function($connection,$cid)use($db,$observer,$baseline,$ally,&$callbackCalls,$count) {
    transactionCheck($connection===$db,'callback recibe la conexión transaccional real');
    transactionCheck($count($connection,'convocatorias')===$baseline+1 && $count($observer,'convocatorias')===$baseline,'callback se ejecuta antes del commit');
    $connection->query("INSERT INTO formulario_aliado_convocatorias VALUES ($cid,$ally,'bachillerato-286','registro','".str_repeat('c',32)."',NULL)");$callbackCalls++;
});
transactionCheck($id>0 && $callbackCalls===1 && $count($observer,'convocatorias')===$baseline+1,'callback único y commit internacional');
transactionCheck((int)$db->query('SELECT COUNT(*) n FROM formulario_aliado_convocatorias WHERE convocatoria_id='.(int)$id)->fetch_assoc()['n']===1,'asociación internacional persistida');
$failed=$m->crear($data,[1],function($connection,$cid)use($ally) {
    $connection->query("INSERT INTO formulario_aliado_convocatorias VALUES ($cid,$ally,'bachillerato-286','registro','".str_repeat('d',32)."',NULL)");
    throw new DomainException('Asociación internacional rechazada en prueba');
});
transactionCheck($failed===false && $count($observer,'convocatorias')===$baseline+1 && $count($db,'formulario_aliado_convocatorias')===1,'fallo de callback revierte convocatoria y asociación');
$commercial=array_replace($data,['manual_id'=>$manual,'aliado_tipo'=>'manual','aliado_id'=>$manual,'programa_codigo'=>'bachillerato-286']);
$links=$count($db,'marketing_aliado_convocatorias_origen');$manualLinks=$count($db,'marketing_aliado_manual_convocatorias');
$m->iniciarLote();
try {
    $a=$m->crear($commercial,[1],true);$b=$m->crear(array_replace($commercial,['titulo'=>'Segundo programa','programa_codigo'=>'ingles']),[1],true);
    transactionCheck($a>0 && $b>0 && $a!==$b,'lote crea dos convocatorias reales');
    transactionCheck($count($observer,'convocatorias')===$baseline+1,'crear(true) no abre ni confirma una transacción parcial');
    $m->confirmarLote();
} catch(Throwable $e) {$m->cancelarLote();throw $e;}
transactionCheck($count($observer,'convocatorias')===$baseline+3,'commit único del lote');
transactionCheck($count($db,'marketing_aliado_convocatorias_origen')===$links+2 && $count($db,'marketing_aliado_manual_convocatorias')===$manualLinks+2,'ambas relaciones V2/V3 sin duplicados');
// Compatibilidad: una petición histórica con 286 conserva sus asociaciones,
// pero escribe la identidad canónica en tablas con FK al catálogo actual.
$programasGuardados=$db->query("SELECT programa_codigo FROM marketing_aliado_manual_convocatorias WHERE convocatoria_id IN ($a,$b) ORDER BY programa_codigo")->fetch_all(MYSQLI_ASSOC);
transactionCheck(array_column($programasGuardados,'programa_codigo')===['bachillerato-general','ingles'],'alias histórico 286 se guarda como General en el modelo PR114');
$m->iniciarLote();$thrown=false;
try {$m->crear($commercial,[1],true);$m->crear(array_replace($commercial,['manual_id'=>999999999]),[1],true);}
catch(Throwable $e) {
    $thrown=true;
    transactionCheck($count($db,'convocatorias')===$baseline+5 && $count($observer,'convocatorias')===$baseline+3,'crear(true) propaga error sin rollback parcial');
} finally {$m->cancelarLote();}
transactionCheck($thrown && $count($observer,'convocatorias')===$baseline+3,'error del segundo programa revierte todo el lote');
transactionCheck($count($db,'marketing_aliado_convocatorias_origen')===$links+2 && $count($db,'marketing_aliado_manual_convocatorias')===$manualLinks+2 && $count($db,'formulario_aliado_convocatorias')===1,'rollback conserva asociaciones anteriores y elimina sólo las nuevas');
$reflection=new ReflectionClass(ConvocatoriaModel::class);
foreach(['crear','iniciarLote','confirmarLote','cancelarLote'] as $method)transactionCheck($reflection->hasMethod($method),'método de clase independiente '.$method);
echo "PASS $checks comprobaciones callback PR114 y lote V3\n";
