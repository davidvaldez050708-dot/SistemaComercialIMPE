<?php
/** Reproduce el condicional real del historial general de Hostinger. */
declare(strict_types=1);
$root=dirname(__DIR__);
$helper=$root.'/tools/conciliar_telefonia_supervision_vista_v1.php';
$original=$root.'/app/views/telefonia/index.php';
$tmp=sys_get_temp_dir().'/impe-supervision-view-'.bin2hex(random_bytes(6)).'.php';
try{
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($helper).' '.
        escapeshellarg($original).' '.escapeshellarg($tmp).' 2>&1';
    $output=[];$code=0;
    exec($cmd,$output,$code);
    if($code!==0)throw new RuntimeException('No se pudo conciliar vista base: '.implode(' | ',$output));
    $old=file_get_contents($original);
    $new=file_get_contents($tmp);
    if(!is_string($old)||!is_string($new))throw new RuntimeException('Archivos ilegibles');
    if(substr_count($new,"'estado_pbx'")!==1 ||
       substr_count($new,"'resultado_estado'")!==1 ||
       strlen($new)<=strlen($old))throw new RuntimeException('Faltan las etiquetas nuevas');
    if(strpos($new,'telephony-control-history-rows')===false ||
       strpos($new,'telephony-control-kpi-grid')===false)
       throw new RuntimeException('Se perdió el diseño institucional.');
    if(strpos($new, "<td><?= \$call['conectada'] ? 'Conectada' : 'Sin conexión confirmada' ?></td>")!==false)
       throw new RuntimeException('Estado anterior persiste.');
    $changedBack=str_replace([
        <<<'A'
<td><?= $esc((string)($call['estado_pbx'] ?? ($call['conectada'] ? 'Conectada' : 'Sin conexión confirmada'))) ?></td>
A,
        <<<'A'
: ($call['proceso'] === 'Ventas'
                                         ? (string)($call['resultado_estado'] ?? 'Sin clasificar')
                                         : ($call['proceso'] === 'Recepción'
                                             ? ($call['conectada'] ? 'Atendida' : 'Sin respuesta confirmada')
                                             : 'Sin clasificar')));
A
    ],[
        <<<'A'
<td><?= $call['conectada'] ? 'Conectada' : 'Sin conexión confirmada' ?></td>
A,
        <<<'A'
: ($call['proceso'] === 'Recepción'
                                         ? ($call['conectada'] ? 'Atendida' : 'Sin respuesta confirmada')
                                         : 'Sin clasificar'));
A
    ],$new,$n);
    if($changedBack!==$old||$n!==2)
        throw new RuntimeException('El conciliador cambió más de dos expresiones');
    $lint=[];$lintCode=0;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1',$lint,$lintCode);
    if($lintCode!==0)throw new RuntimeException('Vista no válida: '.implode(' | ',$lint));
    echo "PASS: dos etiquetas exactas; resto de la vista sin cambios; sintaxis PHP válida.\n";
}finally{
    if(file_exists($tmp))unlink($tmp);
}
