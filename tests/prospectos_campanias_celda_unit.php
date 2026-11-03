<?php
/** El origen de Aliados no debe mostrar una campaña inexistente. */
if (PHP_SAPI!=='cli') exit(1);
$partial=__DIR__.'/../app/views/prospectos/partials/campanias_celda.php';
$esc=static fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$render=static function(string $section,array $campaigns) use ($partial,$esc) {
    $seccionProspectos=$section;$r=['campanias'=>$campaigns];
    ob_start();require $partial;return trim(ob_get_clean());
};
if ($render('todos',[])!=='') throw new RuntimeException('Todos añade una campaña vacía.');
if ($render('meta',[])!=='<span class="central-campaign-none">—</span>') throw new RuntimeException('Falta valor neutro en Campañas.');
$item=[['nombre'=>'CAMPAÑA QUERETARO','id'=>'c:123']];
$out=$render('todos',$item);
if (!str_contains($out,'Campañas (1)') || !str_contains($out,'CAMPAÑA QUERETARO') || str_contains($out,'Campaña: —')) throw new RuntimeException('Campaña real no aparece.');
if (str_contains($render('todos',[['nombre'=>'<script>alert(1)</script>','id'=>'id:1']]),'<script>')) throw new RuntimeException('Campaña sin escape HTML.');
echo "PASS: procedencia sin campaña vacía, campaña real consultable, Meta sin nombre neutro y salida escapada.\n";
