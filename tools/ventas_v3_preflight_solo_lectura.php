<?php
/**
 * Preflight V3: CLI, solo consultas agregadas y esquema de Gestión comercial.
 * Nunca publica, modifica prospectos, altera telefonía, instala migraciones
 * ni imprime contactos, teléfonos, nombres, PBX o credenciales.
 *
 * Ejecutar desde la raíz del sitio auditado: php tools/ventas_v3_preflight_solo_lectura.php
 * No insertar en páginas web ni crear tareas programadas con este archivo.
 */
if (PHP_SAPI!=='cli') {
    http_response_code(403);
    exit('Solo se permite desde CLI.');
}
// Se permite ejecutar una copia privada fuera de public_html.
$siteRoot=getenv('IMPE_SITE_ROOT')?:dirname(__DIR__);
$siteRoot=realpath($siteRoot);
if(!$siteRoot || !is_file($siteRoot.'/config/db_connection.php')){
    fwrite(STDERR,"BLOQUEADO: raíz de sitio no válida. No se consultó la base de datos.\n");
    exit(2);
}
require_once $siteRoot.'/config/db_connection.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);

$tablas=[
    'prospecto_asignaciones'=>['id','asesor_id','vigente'],
    'prospecto_embudo_etapas'=>['asignacion_id','etapa','actualizado_at'],
    'prospecto_embudo_llamadas'=>['id','asignacion_id','usuario_id',
        'extension','pbx_call_id','resultado','creado_at'],
    'telefonia_ventas_llamadas'=>['id','usuario_id','extension','pbx_call_id',
        'direccion','fecha'],
    'telefonia_ventas_resultados'=>['id','usuario_id','extension','pbx_call_id',
        'resultado','proxima_accion_at'],
    'telefonia_ventas_resultado_detalles'=>['resultado_id','catalogo_version',
        'contacto_antes_corte'],
    'telefonia_ventas_resultado_selecciones'=>['resultado_id','codigo','posicion'],
    'prospecto_asesores_horarios'=>[],
    'prospecto_embudo_subetapas'=>['asignacion_id','subetapa','version'],
    'prospecto_embudo_subetapas_historial'=>['asignacion_id','etapa_en_evento',
        'subetapa_anterior','subetapa_nueva']
];
$db=null;$faltantes=0;
try{
    // Una conexión temporal y autocerrada. No crear pool o workers PHP extra.
    $db=(new Database())->connect(false);
    $db->query('SET SESSION TRANSACTION READ ONLY');
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
    echo "=== VENTAS V3 · DIAGNOSTICO DE SOLO LECTURA ===\n";
    $s=$db->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $col=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $presentes=[];
    foreach($tablas as $tabla=>$requeridas){
        $s->bind_param('s',$tabla);$s->execute();
        $row=$s->get_result()->fetch_assoc();
        if(!$row){
            echo 'AUSENTE: '.$tabla."\n";
            if(!in_array($tabla,['prospecto_embudo_subetapas','prospecto_embudo_subetapas_historial'],true))
                $faltantes++;
            continue;
        }
        $presentes[$tabla]=true;
        if(strtoupper((string)$row['ENGINE'])!=='INNODB'){
            echo 'INCOMPATIBLE: '.$tabla.' no es InnoDB'."\n";$faltantes++;
            continue;
        }
        $col->bind_param('s',$tabla);$col->execute();
        $columnas=array_column($col->get_result()->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');
        $no=array_values(array_diff($requeridas,$columnas));
        if($no) {
            echo 'FALTAN COLUMNAS: '.$tabla.' · '.implode(', ',$no)."\n";
            $faltantes++;
        }else{
            echo 'ESTRUCTURA OK: '.$tabla."\n";
        }
    }
    $s->close();$col->close();

    if(isset($presentes['prospecto_embudo_etapas'])){
        $res=$db->query('SELECT etapa,COUNT(*) total FROM prospecto_embudo_etapas
            GROUP BY etapa ORDER BY etapa');
        echo "=== ETAPAS EXISTENTES (SOLO TOTALES) ===\n";
        foreach($res->fetch_all(MYSQLI_ASSOC) as $row) {
            $code=(string)$row['etapa'];
            $label=preg_match('/^[A-Z0-9_]{1,40}$/D',$code)?$code:'NO_RECONOCIDO';
            echo $label.': '.(int)$row['total']."\n";
        }
    }
    if(isset($presentes['prospecto_asignaciones'],
             $presentes['prospecto_embudo_etapas'])){
        $row=$db->query('SELECT COUNT(*) total FROM prospecto_asignaciones a
            LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
            WHERE a.vigente=1 AND e.asignacion_id IS NULL')->fetch_assoc();
        echo 'ASIGNACIONES VIGENTES SIN ETAPA EXPLICITA: '.(int)$row['total']."\n";
    }

    $db->commit();
    echo $faltantes===0
        ? "CHECK OK: sin incompatibilidades estructurales detectadas; no significa migracion autorizada.\n"
        : "CHECK BLOQUEADO: hay faltantes/incompatibilidades; no publicar nueva logica.\n";
    echo "Solo lectura. Sin SQL de modificación y sin datos personales en pantalla.\n";
    exit($faltantes===0?0:2);
}catch(Throwable $e){
    if($db instanceof mysqli){
        try{$db->rollback();}catch(Throwable $ignored){}
    }
    // No revelar detalles ni rutas de configuración privada.
    fwrite(STDERR,"ERROR DE PREFLIGHT: no fue posible completar la inspeccion. Sitio intacto.\n");
    exit(3);
}finally{
    if($db instanceof mysqli){
        try{$db->close();}catch(Throwable $ignored){}
    }
}
