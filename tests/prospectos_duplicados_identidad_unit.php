<?php
/**
 * Contrato de duplicados: mismo evento de Meta y mismo envío de Aliados.
 * No fusionar automáticamente por teléfono o correo.
 */
require_once __DIR__.'/../app/services/ProspectoMetaArchivo.php';
require_once __DIR__.'/../app/helpers/ProgramaEducativo.php';
$checks=0;
$ok=static function(bool $cond,string $msg)use(&$checks):void {
    if (!$cond) throw new RuntimeException('FALLÓ: '.$msg);
    $checks++;
};
$filas=[
    ['id','created_time','full_name','phone_number','form_id','form_name','campaign_name'],
    ['l:000000001234567890','2026-11-06T23:30:00+00:00','Persona Ficticia','p:+525512345678','f:111','Titulación','Campaña A'],
    ['l:000000001234567890','2026-11-06T23:30:00+00:00','Persona Ficticia','p:+525512345678','f:111','Titulación','Campaña A'],
    ['l:000000001234567891','2026-11-06T23:30:00+00:00','Persona Ficticia','p:+525512345678','f:111','Titulación','Campaña B']
];
$segundo=[
    $filas[0],
    ['l:000000001234567890','2026-11-06T23:30:00+00:00','Persona Ficticia','p:+525512345678','f:111','Titulación','Campaña A'],
    ['l:000000001234567892','2026-11-06T23:30:00+00:00','Persona Ficticia','p:+525512345678','f:222','Bachillerato','Campaña C']
];
$escribir=static function($archivo,$data) {
    $f=fopen($archivo,'wb');
    foreach($data as $row) fputcsv($f,$row,"\t");
    fclose($f);
};
$a=tempnam(sys_get_temp_dir(),'impe_meta_dedup_a_');
$b=tempnam(sys_get_temp_dir(),'impe_meta_dedup_b_');
try {
    $escribir($a,$filas);$escribir($b,$segundo);
    $parsedA=ProspectoMetaArchivo::leer($a,'exportacion_a.tsv');
    $parsedB=ProspectoMetaArchivo::leer($b,'exportacion_b.tsv');
    $ok($parsedA['validas']===2,'duplicado dentro de archivo ignorado');
    $ok($parsedA['repetidas_archivo']===1,'contador de repetidos dentro del archivo');
    $ok($parsedB['validas']===2,'otro archivo puede tener múltiples leads');
    $idsA=array_column($parsedA['filas'],'id');
    $idsB=array_column($parsedB['filas'],'id');
    $ok(count(array_intersect($idsA,$idsB))===1,'identificador repetido entre dos archivos');
    $ok($idsA[0]==='000000001234567890','ID de Meta canónico preserva ceros iniciales');
    $ok($parsedA['filas'][0]['phone_number']===$parsedA['filas'][1]['phone_number'] &&
        $parsedA['filas'][0]['id']!==$parsedA['filas'][1]['id'],
        'mismo teléfono NO implica mismo evento Meta');
    $ok($parsedB['filas'][1]['form_id']==='f:222','se conserva el formulario de Bachillerato');
    $base=['persona@example.test','5512345678','1990-01-01','bachillerato-general'];
    [$igual]=ProgramaEducativo::clavesProcedencia($base);
    [$reenvio]=ProgramaEducativo::clavesProcedencia($base);
    $ok(hash_equals($igual,$reenvio),'Aliados: reenvío idéntico genera la misma clave');
    [$programaDistinto]=ProgramaEducativo::clavesProcedencia(
        ['persona@example.test','5512345678','1990-01-01','titulo']
    );
    $ok(!hash_equals($igual,$programaDistinto),
        'Aliados: otro programa constituye solicitud diferente');
    [$convocatoria1]=ProgramaEducativo::clavesProcedencia($base,8);
    [$convocatoria2]=ProgramaEducativo::clavesProcedencia($base,9);
    $ok(!hash_equals($convocatoria1,$convocatoria2),
        'Aliados: convocatorias distintas conservan identidad de solicitud');
    $ok(!hash_equals($igual,$convocatoria1),
        'Aliados: envío general y convocatoria no se confunden');
} finally {
    @unlink($a);@unlink($b);
}
echo "OK: $checks pruebas de identidad Meta/Aliados; no hay fusión automática por teléfono.\n";
