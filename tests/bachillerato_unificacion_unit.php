<?php
require_once __DIR__.'/../app/helpers/ProgramaEducativo.php';
require_once __DIR__.'/../app/controllers/FormularioController.php';
require_once __DIR__.'/../app/controllers/FormularioPublicoController.php';
require_once __DIR__.'/../app/controllers/ConvocatoriaController.php';
$checks=0;$check=static function($value,$label)use(&$checks){if(!$value)throw new RuntimeException($label);$checks++;echo "OK $label\n";};
$check(ProgramaEducativo::codigo('bachillerato-286')==='bachillerato-general','código anterior acepta la identidad única');
foreach(['Bachillerato 286','Bachillerato-286','Bachillerato286'] as $name)$check(ProgramaEducativo::nombre($name)==='Bachillerato General','nombre anterior normalizado: '.$name);
$check(ProgramaEducativo::texto('SEP Acuerdo 286 / Bachillerato 286')==='SEP Acuerdo 286 / Bachillerato General','se conserva la referencia legal SEP');
$check(ProgramaEducativo::codigo('ingles')==='ingles'&&ProgramaEducativo::nombre('Bachillerato en 2 años')==='Bachillerato en 2 años','los otros programas no cambian');
foreach([FormularioController::class,FormularioPublicoController::class] as $class){
    $method=new ReflectionMethod($class,'perfilesInteres');$values=$method->invoke(new $class());
    $check(count(array_keys($values,'Bachillerato General',true))===1&&!in_array('Bachillerato 286',$values,true),'Registro e Inscripción ofrecen un único Bachillerato: '.$class);
}
[$entries,$files]=ProgramaEducativo::lote(['bachillerato-286'=>['clasificacion'=>'bachillerato|bachillerato-286']],['name'=>['bachillerato-286'=>'imagen.png'],'tmp_name'=>['bachillerato-286'=>'/tmp/prueba']]);
$check(array_keys($entries)===['bachillerato-general']&&$entries['bachillerato-general']['clasificacion']==='bachillerato|bachillerato-general','solicitud de convocatoria antigua normalizada');
$check($files['name']===['bachillerato-general'=>'imagen.png']&&$files['tmp_name']===['bachillerato-general'=>'/tmp/prueba'],'imagen conserva su asociación al normalizar');
try{ProgramaEducativo::lote(['bachillerato-286'=>[],'bachillerato-general'=>[]],[]);throw new RuntimeException('Aceptó un duplicado');}catch(DomainException $e){$check(true,'lote con ambos alias se rechaza sin crear duplicados');}
$method=new ReflectionMethod(ConvocatoriaController::class,'esClasificacionValida');
$check($method->invoke(new ConvocatoriaController(),'bachillerato','bachillerato-286',false),'enlace anterior continúa válido como alias');
$identity=['prueba@example.test','7778889900','1990-01-01','bachillerato-286'];
$keys=ProgramaEducativo::clavesProcedencia($identity,42);
$check($keys[1]===hash('sha256',hash('sha256',json_encode($identity,JSON_UNESCAPED_UNICODE)).':42'),'deduplicación internacional conserva la clave anterior de su convocatoria');
$check(!array_intersect($keys,ProgramaEducativo::clavesProcedencia($identity,43)),'dos convocatorias diferentes no se confunden al deduplicar');
$root=realpath(__DIR__.'/../app');$found=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){
    if($file->getExtension()!=='php'||$file->getFilename()==='ProgramaEducativo.php')continue;
    if(preg_match('/bachillerato(?:\s+|-)?286/i',file_get_contents($file->getPathname())))$found[]=$file->getPathname();
}
$check(!$found,'escaneo completo de código activo sin opciones ni etiquetas retiradas: '.implode(',',$found));
echo "PASS $checks comprobaciones de identidad, entradas y escaneo activo; sin SQL\n";
