<?php
/** Probar el conciliador con los fragmentos reales de Hostinger, sin BD. */
declare(strict_types=1);
$root=dirname(__DIR__);
$patch=$root.'/tools/conciliar_supervision_tarjetas_hostinger_v1.php';
$tmp=sys_get_temp_dir().'/impe-supervision-cards-'.bin2hex(random_bytes(5));
if(!mkdir($tmp,0700))throw new RuntimeException('No se pudo crear staging.');
$oldBadges=<<<'PHP'
    <div class="linkage-call-result-group"><span class="linkage-call-result-badge <?= $c['conectada']?'is-contact':'' ?>"><?= $esc($c['direccion']) ?> · <?= $c['conectada']?'Conectada':'Sin conexión confirmada' ?></span><span class="linkage-call-result-badge is-result"><?= $esc($c['resultado_texto']) ?></span></div>
PHP;
$oldButton=<<<'PHP'
     <button type="button" class="linkage-incoming-open" data-supervision-detail><i class="bi bi-card-checklist" aria-hidden="true"></i> Consultar resultado</button>
PHP;
$run=static function($source,$output) use($patch) {
    $lines=[];$exit=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($patch).' '.
        escapeshellarg($source).' '.escapeshellarg($output).' 2>&1',$lines,$exit);
    return [$exit,implode(' | ',$lines)];
};
try{
    $before=$tmp.'/before.php';$after=$tmp.'/after.php';$again=$tmp.'/again.php';
    $view='<?php foreach($data["llamadas"] as $c): ?>'."\n".
        '<section data-supervision-calls><link href="supervision_llamadas.css">'."\n".
        '<div class="supervision-person">Mantener fotografías, nombre y filtros</div>'."\n".
        $oldBadges."\n".
        '<div data-supervision-player><span data-supervision-audio>Audio existente</span>'."\n".
        $oldButton."\n".
        '</div></section><?php endforeach; ?>';
    file_put_contents($before,$view);
    [$exit,$message]=$run($before,$after);
    if($exit!==0)throw new RuntimeException("Conciliador: $message");
    $content=file_get_contents($after);
    foreach([
        'IMPE_SUPERVISION_TARJETAS_PBX_ESTADO_V1',
        "(!empty(\$c['resultado_guardado']))",
        "if(\$resultadoGuardado)",
        '$estadoPbx',
        "supervision_llamadas.css",
        "data-supervision-audio",
        "Mantener fotografías, nombre y filtros",
        "data-supervision-detail"
    ] as $needle) {
        if($needle==="(!empty(\$c['resultado_guardado']))")continue;
        if(strpos($content,$needle)===false)throw new RuntimeException("Falta: $needle");
    }
    if(strpos($content,$oldBadges)!==false || strpos($content,$oldButton)!==false)
        throw new RuntimeException('El botón o etiqueta antigua sigue sin proteger.');
    $lint=[];$code=0;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($after).' 2>&1',$lint,$code);
    if($code!==0)throw new RuntimeException("Falla de sintaxis: ".implode(' | ',$lint));
    [$exit,$message]=$run($after,$again);
    if($exit!==0 || file_get_contents($again)!==$content)
        throw new RuntimeException('Conciliación no idempotente.');
    file_put_contents($before,$view.$oldButton);
    [$exit,$message]=$run($before,$tmp.'/bad.php');
    if($exit===0)throw new RuntimeException('Se aceptaron botones duplicados.');
    echo "PASS: tarjetas preservadas, botón guardado, PHP válido e idempotencia.\n";
}finally{
    foreach(glob($tmp.'/*')?:[] as $path)unlink($path);
    rmdir($tmp);
}
