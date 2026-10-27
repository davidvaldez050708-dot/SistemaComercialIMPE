<?php
/** Pruebas del parche preciso del botón en la vista personalizada de Hostinger. */
declare(strict_types=1);
$root=dirname(__DIR__);
$patch=$root.'/tools/conciliar_vista_ventas_canceladas_v2.php';
if(!is_file($patch))throw new RuntimeException('Falta conciliador.');
$dir=sys_get_temp_dir().'/impe-ventas-parche-'.bin2hex(random_bytes(4));
if(!mkdir($dir,0700))throw new RuntimeException('No se pudo crear staging de pruebas.');
$cases=[
    'original'=>"<?php if(\$ref!==''&&\$call['finalizada']): ?>",
    'espaciada'=>"<?php if ( \$ref !== '' && \$call['finalizada'] ): ?>",
    'invertida'=>"<?php if (\$call['finalizada'] && \$ref !== ''): ?>",
    'protegida'=>"<?php if(\$ref!==''&&(!empty(\$call['registro'])||TelefoniaVentasLlamadasService::resultadoCapturable(\$call))): ?>"
];
try{
  foreach($cases as $type=>$condition){
    $input=$dir.'/'.$type.'.php';
    $output=$dir.'/'.$type.'.out.php';
    $markup='<div class="personalizacion-no-modificar">Mi diseño del CRM</div>'."\n".
      $condition.'<button type="button" data-sales-detail="PBX_TEST">Registrar resultado</button><?php endif; ?>'."\n".
      '<div class="mis-llamadas-personalizada">Estilos preservados</div>';
    file_put_contents($input,$markup);
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($patch).' '.escapeshellarg($input).' '.escapeshellarg($output).' 2>&1';
    $out=[];$status=0;exec($command,$out,$status);
    if($status!==0)throw new RuntimeException('Fallo variante '.$type.': '.implode(' | ',$out));
    $new=file_get_contents($output);
    if(strpos($new,'Mi diseño del CRM')===false ||
       strpos($new,'Estilos preservados')===false ||
       substr_count($new,'TelefoniaVentasLlamadasService::resultadoCapturable($call)')!==1)
       throw new RuntimeException('Personalización perdida en '.$type);
    if($type==='protegida'&&$markup!==$new)throw new RuntimeException('Parche no idempotente.');
    if(strpos($new,'data-sales-detail')===false)throw new RuntimeException('Se eliminó el botón.');
  }
  $input=$dir.'/ambigua.php';
  $output=$dir.'/ambigua.out.php';
  file_put_contents($input,'<button data-sales-detail="x">Registrar resultado</button>');
  $out=[];$status=0;
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($patch).' '.escapeshellarg($input).' '.escapeshellarg($output).' 2>&1',$out,$status);
  if($status===0||file_exists($output))throw new RuntimeException('Aceptó vista no reconocida.');
  echo "PASS: vista original, variaciones, personalizaciones, idempotencia y rechazo seguro.\n";
}finally{
  foreach(glob($dir.'/*')?:[] as $file)unlink($file);
  rmdir($dir);
}
