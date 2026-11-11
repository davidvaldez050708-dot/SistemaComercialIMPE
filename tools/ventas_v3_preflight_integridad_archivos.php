<?php
/**
 * Informe local CLI sin conexión ni SQL para verificar archivos Hostinger
 * antes de conciliar cualquier despliegue de Gestión comercial V3.
 *
 * NO modifica archivos, permisos, configuraciones, bases de datos ni PBX.
 * El hash se calcula como blob Git SHA-1, no sha1(file).
 *
 * php tools/ventas_v3_preflight_integridad_archivos.php --site ... --check
 */
if (PHP_SAPI!=='cli'){
    http_response_code(403);
    exit("BLOQUEADO: solo consola.\n");
}
if($argc!==4 || $argv[1]!=='--site' || $argv[3]!=='--check'){
    fwrite(STDERR,"BLOQUEADO: use --site RUTA --check.\n");
    exit(2);
}
$site=realpath($argv[2]);
if(!$site || !is_dir($site) || !is_file($site.'/config/db_connection.php')){
    fwrite(STDERR,"BLOQUEADO: raíz del CRM no válida.\n");
    exit(2);
}
$self=realpath(__FILE__);
if($self && strpos($self,$site.DIRECTORY_SEPARATOR)===0){
    fwrite(STDERR,"BLOQUEADO: mantenga este diagnóstico fuera de public_html.\n");
    exit(2);
}

// Manifest firmado por las referencias Git reales de PR #247 -> PR #255.
// null significa un archivo NUEVO de V3 que se puede agregar, pero NO
// reemplazar si ya existe con otra personalización.
$manifest=[
    'app/controllers/EmbudoComercialController.php'=>
        ['fbfc356787390bce06edb8678057c71c95c8a6e0','a4342e88288321567487b20f0fa80c79564ad465'],
    'app/services/EmbudoComercialRepository.php'=>
        ['0f562e8bab496a2049c70e49ab64e0a3d7398c1a','f9f8c7b43a8c12e9a02ece1be1132431fa2fea5b'],
    'app/services/EmbudoComercialResultadoBridge.php'=>
        ['6d990a8b12d4856c83e61dc67ad87b34e7767748','cab8890f6773f2acb69f5b8e6ead312b321325a1'],
    'app/services/EmbudoComercialMovimientoService.php'=>
        ['9621a3e2a2b96d0c89ecd597a17c323c4ee09139','b8ff5c14127fcf049ff1080f1e9ae3fd181fb362'],
    'app/services/EmbudoComercialEtapas.php'=>
        ['a2cc9493b3d5b980096c768b15fecbfa9af710a8','a2cc9493b3d5b980096c768b15fecbfa9af710a8'],
    'app/services/EmbudoComercialPoliticaPropuesta.php'=>
        [null,'e5ec54f6fac872d09bf534a06debf7e38ee410c1'],
    'app/services/EmbudoComercialIntentosPbxRepository.php'=>
        [null,'072f5e829f8377fd486b8b7c83341d5df2ecd132'],
    'app/services/EmbudoComercialPlanificadorV3.php'=>
        [null,'0591ad49d3d5179ddbb38d961c5bf4594519eab9'],
    'app/services/EmbudoComercialV3PilotoControlado.php'=>
        [null,'bed4132f4fb04a89502fc2c9d51d96f4b218d0ad'],
    'app/services/EmbudoComercialV3IndicadoresVista.php'=>
        [null,'c7419b2521392c48a0eebefb54bc9c7fd1cfda1c'],
    'app/views/embudo_comercial/index.php'=>
        ['825b4c6af25450d4be21b47f8666955ada847eed','825b4c6af25450d4be21b47f8666955ada847eed'],
    'app/views/embudo_comercial/previsualizacion_v3.php'=>
        [null,'04f1180034ea0f94ca78fb9eba87a36b96c45fe7'],
    'public/css/embudo_comercial.css'=>
        ['4510e51daf4c27f482a4e6cfcf86b24f0ff5af17','4510e51daf4c27f482a4e6cfcf86b24f0ff5af17'],
    'public/css/embudo_comercial_compacto_v1.css'=>
        ['c0ea09e7b35ee8c54d61f9386902bb8bcbac2ee4','c0ea09e7b35ee8c54d61f9386902bb8bcbac2ee4'],
    'public/css/embudo_v3_indicadores.css'=>
        [null,'57ef9790d4868837e16807a0e56faf214c49219d'],
    'public/javascript/embudo_comercial_movimientos.js'=>
        ['a282167325a85f1ba153dc9b8c6b29092aa3a209','a282167325a85f1ba153dc9b8c6b29092aa3a209'],
    'app/services/TelefoniaResultadoVentasService.php'=>
        ['1d4472ba73e7de4097ef2f592104160b254d7f8f','1d4472ba73e7de4097ef2f592104160b254d7f8f'],
    'app/services/TelefoniaResultadoVentasCatalogo.php'=>
        ['6d5396e4dfb7a5c535ed5b8e964d93fab7a04a88','6d5396e4dfb7a5c535ed5b8e964d93fab7a04a88']
];
echo "=== ARCHIVOS V3 / TELEFONIA · INSPECCION DE SOLO LECTURA ===\n";
$counters=['BASE_V1'=>0,'V3_PREPARADO'=>0,'AUSENTE_NUEVO'=>0,
    'AUSENTE_REQUERIDO'=>0,'PERSONALIZADO'=>0,'BLOQUEADO'=>0];
foreach($manifest as $path=>[$base,$target]){
    $local=$site.'/'.$path;
    if(is_link($local)){
        $estado='BLOQUEADO';
    }elseif(!is_file($local)){
        $estado=$base===null?'AUSENTE_NUEVO':'AUSENTE_REQUERIDO';
    }else{
        $bytes=file_get_contents($local);
        if($bytes===false){
            $estado='BLOQUEADO';
        }else{
            $sha=sha1('blob '.strlen($bytes)."\0".$bytes);
            if(hash_equals($target,$sha)){
                $estado='V3_PREPARADO';
            }elseif($base!==null && hash_equals($base,$sha)){
                $estado='BASE_V1';
            }else{
                $estado='PERSONALIZADO';
            }
        }
    }
    $counters[$estado]++;
    echo $estado.' | '.$path."\n";
}
echo "=== RESUMEN ===\n";
foreach($counters as $estado=>$total){
    echo $estado.': '.$total."\n";
}
$requierenConciliacion=$counters['PERSONALIZADO']+
    $counters['AUSENTE_REQUERIDO']+$counters['BLOQUEADO'];
if($requierenConciliacion>0) {
    echo "CHECK BLOQUEADO: conciliar personalizaciones o archivos ausentes ANTES de publicar.\n";
}else{
    echo "CHECK COMPATIBLE PARA PREPARAR: no detecta conflictos de hash.\n";
}
echo "NO PUBLICADO: sin archivos escritos, SQL, conexiones de BD ni cambios de asesores.\n";
exit($requierenConciliacion>0?3:0);
