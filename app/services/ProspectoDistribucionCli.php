<?php
/**
 * CLI para cron de Hostinger.
 *
 * PREVIEW: php app/services/ProspectoDistribucionCli.php --meta --preview
 * APPLY:   php app/services/ProspectoDistribucionCli.php --meta --apply --confirmar-produccion
 *
 * La jornada por defecto es MAÑANA en America/Mexico_City; ejecutar después
 * de que Tania confirme ambos archivos. NO se habilita cron al publicar.
 * --reintentar-aliados reevalúa registros pendientes, sin crear duplicados.
 */
if (PHP_SAPI!=='cli') {http_response_code(403);exit('Sólo CLI.');}
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/ProspectoDistribucionMotor.php';

$opciones=array_slice($argv,1);
$flags=['--meta','--reintentar-aliados','--preview','--apply','--confirmar-produccion'];
$modo=null;$apply=null;$confirmar=false;$fecha=null;$limite=2000;$rondas=1;$maxSegundos=90;
foreach ($opciones as $op) {
    if ($op==='--meta'||$op==='--reintentar-aliados') {
        if ($modo!==null) throw new InvalidArgumentException('Escoge un solo motor.');
        $modo=$op;
    } elseif ($op==='--preview'||$op==='--apply') {
        if ($apply!==null) throw new InvalidArgumentException('Escoge preview o apply.');
        $apply=$op==='--apply';
    } elseif ($op==='--confirmar-produccion') {
        $confirmar=true;
    } elseif (strpos($op,'--rondas=')===0) {
        $v=substr($op,9);
        if (!ctype_digit($v)||(int)$v<1||(int)$v>100) {
            throw new InvalidArgumentException('Las rondas deben estar entre 1 y 100.');
        }
        $rondas=(int)$v;
    } elseif (strpos($op,'--max-segundos=')===0) {
        $v=substr($op,15);
        if (!ctype_digit($v)||(int)$v<10||(int)$v>180) {
            throw new InvalidArgumentException('La duración máxima debe estar entre 10 y 180 segundos.');
        }
        $maxSegundos=(int)$v;
    } elseif (strpos($op,'--fecha=')===0) {
        $fecha=substr($op,8);
    } elseif (strpos($op,'--limite=')===0) {
        $v=substr($op,9);
        if (!ctype_digit($v)||((int)$v)<1||((int)$v)>2000) {
            throw new InvalidArgumentException('El límite debe estar entre 1 y 2000.');
        }
        $limite=(int)$v;
    } else {
        throw new InvalidArgumentException('Argumento no permitido: '.substr($op,0,30));
    }
}
if ($modo===null||$apply===null) {
    fwrite(STDERR,"Uso: php app/services/ProspectoDistribucionCli.php --meta|--reintentar-aliados --preview|--apply [--fecha=AAAA-MM-DD] [--limite=2000] [--rondas=1] [--max-segundos=90] [--confirmar-produccion]\n");
    exit(2);
}
if ($apply&&!$confirmar) {
    fwrite(STDERR,"Bloqueado: para asignaciones reales agrega --confirmar-produccion.\n");
    exit(2);
}
if ($modo==='--reintentar-aliados'&&($fecha!==null||$rondas!==1)) {
    fwrite(STDERR,"Las rondas y la fecha sólo corresponden a Meta.\n");exit(2);
}
if ($modo==='--meta' && !$apply && $rondas!==1) {
    fwrite(STDERR,"Para simulación sin escrituras se permite una ronda por ejecución.\n");exit(2);
}
if ($modo==='--reintentar-aliados'&&$fecha!==null) {
    fwrite(STDERR,"La fecha sólo corresponde al reparto Meta.\n");exit(2);
}
$hoy=new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City'));
$fecha=$fecha??ProspectoDistribucionReglas::fechaSiguiente($hoy);
if ($modo==='--meta' && $apply && $fecha < $hoy->format('Y-m-d')) {
    fwrite(STDERR,"No se permite repartir Meta para una fecha pasada.\n");
    exit(2);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=(new Database())->connect(false);
    $engine=new ProspectoDistribucionMotor($db);
    $started=microtime(true);
    if ($modo==='--meta') {
        $acum=['inspeccionados'=>0,'asignados'=>0,'pendientes'=>0,'motivos'=>[]];
        $res=[];
        $realizadas=0;
        for ($i=0;$i<$rondas;$i++) {
            // El cursor se guarda sólo con --apply y se retoma en otra
            // ejecución de cron si se alcanza el tiempo o las rondas.
            $r=$engine->distribuirMeta($fecha,$limite,$apply);
            $realizadas++;
            foreach (['inspeccionados','asignados','pendientes'] as $k) {
                $acum[$k]+=(int)$r[$k];
            }
            foreach ($r['motivos'] as $k=>$n) {
                $acum['motivos'][$k]=($acum['motivos'][$k]??0)+$n;
            }
            $res=$r;
            if (!empty($r['terminado'])||$r['inspeccionados']===0 ||
                microtime(true)-$started >= $maxSegundos) break;
        }
        $res=array_merge($res,$acum);
        $res['rondas']=$realizadas;
        $res['continuacion_pendiente']=empty($res['terminado']);
    } else {
        $res=$engine->reintentarAliados($limite,$apply);
    }
    // Sin IDs de prospectos, teléfonos, nombres ni tokens de Meta.
    echo json_encode(
        ['motor'=>$modo==='--meta'?'META':'ALIADOS','ejecucion'=>$apply?'APLICADA':'SIMULACION']+$res,
        JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR
    ),"\n";
    $db->close();
} catch (Throwable $e) {
    error_log('[motor_distribucion_cli] '.get_class($e).' codigo='.(int)$e->getCode());
    fwrite(STDERR,"ERROR: no se pudo completar el proceso. Revisa el registro privado del servidor.\n");
    exit(1);
}
