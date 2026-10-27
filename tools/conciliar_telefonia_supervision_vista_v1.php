<?php
/** Conciliación exacta de dos etiquetas del historial general personalizado. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR,"Uso: php conciliar_telefonia_supervision_vista_v1.php vista_original.php vista_candidata.php\n");
    exit(2);
}
$original = $argv[1];
$destino = $argv[2];
if (!is_file($original) || is_link($original) || $original === $destino) {
    fwrite(STDERR,"ERROR: origen no válido. SITIO INTACTO.\n");
    exit(1);
}
$src = file_get_contents($original);
if (!is_string($src) || $src === '') {
    fwrite(STDERR,"ERROR: vista vacía o ilegible. SITIO INTACTO.\n");
    exit(1);
}
$oldState = <<<'OLD'
<td><?= $call['conectada'] ? 'Conectada' : 'Sin conexión confirmada' ?></td>
OLD;
$newState = <<<'NEW'
<td><?= $esc((string)($call['estado_pbx'] ?? ($call['conectada'] ? 'Conectada' : 'Sin conexión confirmada'))) ?></td>
NEW;
$oldResult = <<<'OLD'
: ($call['proceso'] === 'Recepción'
                                        ? ($call['conectada'] ? 'Atendida' : 'Sin respuesta confirmada')
                                        : 'Sin clasificar'));
OLD;
$newResult = <<<'NEW'
: ($call['proceso'] === 'Ventas'
                                        ? (string)($call['resultado_estado'] ?? 'Sin clasificar')
                                        : ($call['proceso'] === 'Recepción'
                                            ? ($call['conectada'] ? 'Atendida' : 'Sin respuesta confirmada')
                                            : 'Sin clasificar')));
NEW;
if (substr_count($src,$oldState) !== 1 || substr_count($src,$oldResult) !== 1) {
    fwrite(STDERR,"CONFLICTO: las dos expresiones originales no coinciden exactamente o están repetidas.\n");
    fwrite(STDERR,"SITIO INTACTO. No sustituir ni alterar otros bloques de la vista.\n");
    exit(1);
}
$candidate = str_replace([$oldState,$oldResult],[$newState,$newResult],$src,$count);
if ($count !== 2 || substr_count($candidate,"'resultado_estado'") !== 1 ||
    substr_count($candidate,"'estado_pbx'") !== 1 ||
    strpos($candidate,'data-telephony-control-history-rows') === false) {
    fwrite(STDERR,"ERROR: candidato inesperado. SITIO INTACTO.\n");
    exit(1);
}
if (file_put_contents($destino,$candidate) !== strlen($candidate)) {
    fwrite(STDERR,"ERROR: no se pudo escribir el candidato temporal. SITIO INTACTO.\n");
    exit(1);
}
echo "VISTA PREPARADA: exclusivamente estado PBX y resultado en la tabla general.\n";
