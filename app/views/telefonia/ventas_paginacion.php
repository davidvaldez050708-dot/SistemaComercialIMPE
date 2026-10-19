<?php
// Sólo presentación: cada bandeja conserva su consulta, permisos y filtros.
$paginaVentas=(int)($paginaVentas??1);$paginasVentas=(int)($paginasVentas??1);
$queryVentas=array_merge($_GET,['controller'=>'telefonia','action'=>'misLlamadas']);
unset($queryVentas['pbx_call_id'],$queryVentas['extension']);
if(($vistaVentas??'')!=='')$queryVentas['vista']=$vistaVentas;else unset($queryVentas['vista']);
?>
<nav class="linkage-incoming-pagination" aria-label="Páginas de llamadas"><span><?= (int)$totalVentas ?> registros</span><div class="linkage-incoming-pagination-pages">
<?php foreach(['Anterior'=>$paginaVentas-1,'Siguiente'=>$paginaVentas+1] as $label=>$n):$disabled=$n<1||$n>$paginasVentas;$url=BASE_URL.'index.php?'.http_build_query(array_merge($queryVentas,['pagina'=>$n])); ?>
 <a class="linkage-incoming-pagination-arrow <?= $disabled?'disabled':'' ?>" href="<?= $esc($disabled?'#':$url) ?>" aria-disabled="<?= $disabled?'true':'false' ?>" <?= $disabled?'tabindex="-1"':'' ?>><?= $label ?></a>
<?php endforeach; ?><span><?= $paginaVentas ?> / <?= $paginasVentas ?></span></div></nav>
