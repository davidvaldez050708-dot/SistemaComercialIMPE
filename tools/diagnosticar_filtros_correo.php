<?php
// Diagnóstico CLI de sólo lectura. Sin bootstrap web, tokens, SMTP ni servicios de envío.
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
ini_set('display_errors','0');
$options=getopt('',['root:']);
$root=realpath((string)($options['root']??dirname(__DIR__)));
if(!$root || !is_file($root.'/app/models/CorreoConvocatoriaConsultaModel.php')) { fwrite(STDERR,"FILTROS_CODIGO_NO_INSTALADO\n");exit(2); }
try {
    require_once $root.'/app/models/CorreoConvocatoriaConsultaModel.php';
    $db=(new Database())->connect();
    $catalog=(new CorreoConvocatoriaConsultaModel($db))->catalogo();
    $out=['version_diagnostico'=>'filtros-correo-v2','fuente'=>'base_de_datos_de_la_raiz_indicada',
        'estados'=>count($catalog['estados']),'convocatorias_activas'=>count($catalog['convocatorias']),
        'convenios_catalogo'=>isset($catalog['convenios'])?count($catalog['convenios']):null,'avisos'=>$catalog['avisos']??[],
        'convocatorias_sin_estados'=>count(array_filter($catalog['convocatorias'],static fn($r)=>!$r['estados'])),
        'convocatorias_sin_convenio'=>count(array_filter($catalog['convocatorias'],static fn($r)=>!$r['convenios']))];
    // Compatible también con v1 instalada: comprobar independientemente el catálogo de aliados.
    try {
        $r=$db->query('SELECT COUNT(DISTINCT s.id) AS total FROM aliados_asignaciones a INNER JOIN seguimientos_vinculacion s ON s.id = a.seguimiento_id INNER JOIN estados e ON e.id = s.estado_id WHERE a.activo = 1 AND s.activo = 1');
        $out['convenios_activos_en_fuente']=(int)$r->fetch_assoc()['total'];
    } catch(Throwable $e) { $out['convenios_activos_en_fuente']=null;$out['codigo_consulta_convenios']=(int)$e->getCode(); }
    foreach(['app/models/CorreoConvocatoriaConsultaModel.php','public/javascript/correo_convocatorias.js','public/javascript/selectores_uniformes.js'] as $file) {
        $out['codigo_sha256'][$file]=is_file($root.'/'.$file)?hash_file('sha256',$root.'/'.$file):'AUSENTE';
    }
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    echo "SOLO LECTURA. Sin nombres de contactos, credenciales, envíos, cambios de datos ni recuperación.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'FILTROS_DIAGNOSTICO_ERROR clase='.get_class($error).' codigo='.(int)$error->getCode().". Sin datos privados.\n");exit(2);
}
