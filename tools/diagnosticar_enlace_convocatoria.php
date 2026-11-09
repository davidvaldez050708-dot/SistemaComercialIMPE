<?php
// Sólo SELECT y validación local. No genera enlaces ni consulta/envía al proveedor.
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
ini_set('display_errors','0');
$options=getopt('',['convocatoria:','base:','root:']);
$root=realpath((string)($options['root']??dirname(__DIR__)));
try {
    if(!$root) { throw new InvalidArgumentException('RAIZ_INVALIDA'); }
    require_once $root.'/app/services/CorreoConvocatoriaComposicion.php';
    $id=CorreoConvocatoriaComposicion::id($options['convocatoria']??11);
    $model=new CorreoConvocatoriaConsultaModel();$row=$model->buscar($id);
    if(!$row) { throw new RuntimeException('CONVOCATORIA_NO_EXISTE'); }
    // Referencia de validación CLI; no cambia la URL guardada ni la configuración web.
    $base=(string)($options['base']??'https://fundacionrededumex.org/');
    $link=CorreoConvocatoriaEnlace::obtener($row,$base);
    $image=(new CorreoConvocatoriaComposicion($model,$root))->imagen($row);
    echo json_encode(['version'=>'convocatoria-enlace-v1','id'=>$id,'activa'=>$row['activa'],'imagen_valida'=>(bool)$image,
        'enlace_guardado'=>!empty($row['enlace_ref']),'enlace_disponible'=>(bool)$link,'tipo_formulario'=>$link['tipo']??null,
        'url_conservada'=>$link?hash_equals($row['enlace_ref'],$link['url']):null,
        'sha256_url'=>$link?hash('sha256',$link['url']):null],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    echo "SOLO LECTURA. Sin URLs privadas, tokens de acceso, generación de enlaces, envíos ni cambios de BD.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'ENLACE_CONVOCATORIA_DIAGNOSTICO clase='.get_class($error).' codigo='.(int)$error->getCode().". Sin datos privados.\n");exit(2);
}
