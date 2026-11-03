<?php
/** Administración explícita del almacén. Nunca se ejecuta desde HTTP. */
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
$args=getopt('',['site:','check','apply','migrar','reconciliar','integridad','desde-id:','hasta-id:','limite:']);
try {
    if (isset($args['check'])===isset($args['apply'])) throw new RuntimeException('Selecciona --check o --apply.');
    $site=realpath($args['site']??dirname(__DIR__));
    if (!$site || !is_file($site.'/config/db_connection.php')) throw new RuntimeException('Sitio no válido.');
    $ops=(int)isset($args['migrar'])+(int)isset($args['reconciliar'])+(int)isset($args['integridad']);
    if ($ops>1 || (isset($args['apply']) && $ops!==1) || (isset($args['integridad']) && isset($args['apply']))) throw new RuntimeException('Operación no válida.');
    require $site.'/config/db_connection.php';
    require_once __DIR__.'/../app/services/ProspectoCentralIncorporacionService.php';
    $db=(new Database())->connect(false);
    $schema=ProspectoCentralSchema::revisar($db);$repo=new ProspectoCentralRepository($db);
    if ($schema['conflictos']) throw new RuntimeException('Estructura comercial diferente.');
    $fuentes=['formulario_registros','formulario_aliado_procedencias','formulario_programas'];
    foreach ($fuentes as $tabla) {
        $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$tabla);$s->execute();$r=$s->get_result()->fetch_assoc();
        if (!$r || strtoupper($r['ENGINE'])!=='INNODB') throw new RuntimeException('Fuente original ausente o no transaccional.');
    }
    if (isset($args['migrar']) && isset($args['apply'])) {
        $out=ProspectoCentralSchema::aplicar($db)+['estado'=>'ESTRUCTURA_APLICADA','originales_modificados'=>false];
    } elseif (isset($args['reconciliar'])) {
        $from=filter_var($args['desde-id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $size=filter_var($args['limite']??100,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
        $max=(int)$repo->consulta('SELECT COALESCE(MAX(id),0) n FROM formulario_registros')->fetch_assoc()['n'];
        $to=filter_var($args['hasta-id']??$max,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if ($from===false||$size===false||$to===false||$to<$from) throw new RuntimeException('Límites de lote no válidos.');
        $to=min($max,$to);
        $rows=$repo->consulta('SELECT id FROM formulario_registros WHERE id>? AND id<=? ORDER BY id LIMIT ?',[$from,$to,$size])->fetch_all(MYSQLI_ASSOC);
        $new=0;$linked=0;$errors=[];$originNew=0;$last=$from;
        $svc=new ProspectoCentralIncorporacionService($db);
        if (isset($args['check'])) $db->query('START TRANSACTION READ ONLY');
        foreach ($rows as $row) {
            $last=(int)$row['id'];
            if (isset($args['check'])) {
                $exists=$schema['disponible'] && $repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$row['id']);
                if ($exists) $linked++;else $new++;
            } else {
                if (!$schema['disponible']) throw new RuntimeException('Aplicar primero la migración comercial.');
                try {$r=$svc->incorporarRegistro((int)$row['id']);if ($r['nuevo']) $new++;else $linked++;$originNew+=$r['procedencias_nuevas'];}
                catch (Throwable $e) {$errors[]=['registro_id'=>(int)$row['id'],'codigo'=>(int)$e->getCode(),'tipo'=>get_class($e)];}
            }
        }
        if (isset($args['check'])) $db->rollback();
        $out=['estado'=>isset($args['check'])?'PREVISUALIZACION_SIN_ESCRITURAS':'LOTE_APLICADO','estructura'=>$schema,
            'examinados'=>count($rows),'nuevos'=>$new,'ya_vinculados'=>$linked,'procedencias_nuevas'=>$originNew,
            'ultimo_id'=>$last,'continuar_desde'=>$errors?min(array_column($errors,'registro_id'))-1:$last,
            'hasta_id'=>$to,'terminado'=>!$errors&&($last>=$to || count($rows)<$size),'errores'=>$errors,'originales_modificados'=>false];
    } elseif (isset($args['integridad'])) {
        if (!$schema['disponible']) throw new RuntimeException('Estructura comercial pendiente.');
        $db->query('START TRANSACTION READ ONLY');
        $missing=(int)$repo->consulta("SELECT COUNT(*) n FROM prospecto_origenes o LEFT JOIN formulario_registros r ON r.id=CAST(o.registro_id AS UNSIGNED) WHERE o.sistema_origen='IMPE_FORMULARIOS' AND o.tipo_registro='REGISTRO' AND r.id IS NULL")->fetch_assoc()['n'];
        $children=(int)$repo->consulta("SELECT COUNT(*) n FROM formulario_aliado_procedencias ap WHERE ap.tipo='registro' AND NOT EXISTS(SELECT 1 FROM prospecto_origenes o WHERE o.sistema_origen='IMPE_FORMULARIOS' AND o.tipo_registro='PROCEDENCIA_ALIADO' AND o.identificador_origen=CAST(ap.id AS CHAR))")->fetch_assoc()['n'];
        $coincidencias=(int)$repo->consulta('SELECT COUNT(*) n FROM (SELECT telefono_normalizado FROM prospectos_comerciales WHERE telefono_normalizado IS NOT NULL GROUP BY telefono_normalizado HAVING COUNT(*)>1) c')->fetch_assoc()['n'];
        $out=['estado'=>'INTEGRIDAD_SOLO_LECTURA','estructura'=>$schema,'resumen'=>$repo->resumen(),
            'referencias_originales_ausentes'=>$missing,'procedencias_por_incorporar'=>$children,
            'telefonos_coincidentes_para_revision'=>$coincidencias,'fusion_automatica'=>false];
        $db->rollback();
    } else {
        $db->query('START TRANSACTION READ ONLY');
        $out=['estado'=>'CHECK_SIN_ESCRITURAS','estructura'=>$schema,
            'registros_originales'=>(int)$repo->consulta('SELECT COUNT(*) n FROM formulario_registros')->fetch_assoc()['n'],
            'resumen'=>$schema['disponible']?$repo->resumen():null];
        $db->rollback();
    }
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(!empty($out['errores'])?1:0);
} catch (Throwable $e) {
    // Un error de SQL puede contener datos del valor que falló. No publicarlo.
    fwrite(STDERR,json_encode(['estado'=>'ERROR_SIN_DATOS_PERSONALES','tipo'=>get_class($e),'codigo'=>(int)$e->getCode(),'motivo'=>$e instanceof mysqli_sql_exception?'Consulta SQL no disponible; revisar estructura y permisos.':$e->getMessage()],JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
}
