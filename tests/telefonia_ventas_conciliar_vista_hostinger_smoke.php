<?php
/** Regresión del cambio quirúrgico sobre vistas personalizadas; no requiere BD. */
declare(strict_types=1);
$root=dirname(__DIR__);
$patch=$root.'/tools/conciliar_vista_ventas_canceladas_v2.php';
if(!is_file($patch))throw new RuntimeException('Falta conciliador.');
$dir=sys_get_temp_dir().'/impe-ventas-parche-'.bin2hex(random_bytes(5));
if(!mkdir($dir,0700))throw new RuntimeException('No se pudo crear directorio de pruebas.');
$conditions=[
 'original'=>"<?php if(\$ref!==''&&\$call['finalizada']): ?>",
 'espaciada'=>"<?php if ( \$ref !== '' && \$call['finalizada'] ): ?>",
 'invertida'=>"<?php if (\$call['finalizada'] && \$ref !== ''): ?>",
 'personalizada'=>"<?php if(!empty(\$call['finalizada'])): ?>",
];
$run=static function(string $input,string $output)use($patch):array{
  $lines=[];$code=0;
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($patch).' '.
    escapeshellarg($input).' '.escapeshellarg($output).' 2>&1',$lines,$code);
  return [$code,implode("\n",$lines)];
};
try{
 foreach($conditions as $type=>$condition){
  $input=$dir.'/'.$type.'.php';
  $output=$dir.'/'.$type.'.out.php';
  $again=$dir.'/'.$type.'.again.php';
  $markup='<div class="personalizacion">Mantener diseño</div>'."\n"
    .'<?php foreach($rows as $call): $result=$call["registro"]??null; ?>'."\n"
    .'<?php $pre=TelefoniaVentasLlamadasService::resultadoCapturable($call); ?>'."\n"
    .'<?php $pre2=TelefoniaVentasLlamadasService::resultadoCapturable($call); ?>'."\n"
    .$condition
    .'<button type="button" class="btn-custom" data-sales-detail="<?= $ref ?>">'
    .'<?= $result?"Consultar resultado":"Registrar resultado" ?></button>'
    .'<?php endif; ?>'."\n"
    .'<?php endforeach; ?>'."\n"
    .'<div class="otra-vista">No cambiar</div>';
  file_put_contents($input,$markup);
  [$code,$message]=$run($input,$output);
  if($code!==0)throw new RuntimeException("$type: $message");
  $new=file_get_contents($output);
  if(substr_count($new,'IMPE_VENTAS_GUARD_RESULTADO_V3')!==1 ||
     substr_count($new,'TelefoniaVentasLlamadasService::resultadoCapturable($call)')!==3 ||
     strpos($new,'Mantener diseño')===false || strpos($new,'No cambiar')===false ||
     strpos($new,'btn-custom')===false)
    throw new RuntimeException("Pérdida o cambio inesperado en $type");
  exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($output).' 2>&1',$lint,$lintCode);
  if($lintCode!==0)throw new RuntimeException("Sintaxis PHP incorrecta en $type");
  [$code,$message]=$run($output,$again);
  if($code!==0||file_get_contents($again)!==$new)
    throw new RuntimeException("Parche no idempotente en $type");
 }
 $bad=$dir.'/duplicada.php';$target=$dir.'/duplicada.out.php';
 file_put_contents($bad,'<?php $call=[]; ?>'
   .'<button data-sales-detail="a">Registrar</button>'
   .'<button data-sales-detail="b">Registrar</button>');
 [$code,$message]=$run($bad,$target);
 if($code===0||file_exists($target))throw new RuntimeException('Aceptó dos botones ambiguos.');
 $bad=$dir.'/sin_contexto.php';$target=$dir.'/sin_contexto.out.php';
 file_put_contents($bad,'<button data-sales-detail="x">Registrar resultado</button>');
 [$code,$message]=$run($bad,$target);
 if($code===0||file_exists($target))throw new RuntimeException('Aceptó vista sin contexto $call.');
 echo "PASS: condiciones diversas, referencias repetidas, sintaxis, personalizaciones e idempotencia.\n";
}finally{
 foreach(glob($dir.'/*')?:[] as $item)unlink($item);
 rmdir($dir);
}
