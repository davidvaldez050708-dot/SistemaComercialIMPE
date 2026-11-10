<?php
/**
 * Reasignación autorizada EXCLUSIVAMENTE del fixture embudo fase 2A.
 * Prospecto 14, asignación 1, asesor 8 -> 22, BD u170338188_red_crm.
 *
 * --check: sólo SELECT, no escribe ni respalda.
 * --apply: requiere confirmación literal, backup privado y transacción.
 * Bloquea cualquier divergencia, etapa distinta de NUEVO, resultados PBX,
 * historial de movimientos, ficha no sintética y cualquier otro prospecto.
 *
 * No modifica teléfonos, prospectos, horarios, distribución ni telefonía.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
umask(0077);

const IMPE_FIX_DB='u170338188_red_crm';
const IMPE_FIX_PROSPECTO=14;
const IMPE_FIX_ASIGNACION=1;
const IMPE_FIX_ASESOR_ANTES=8;
const IMPE_FIX_ASESOR_DESPUES=22;
const IMPE_FIX_ORIGEN='EMBUDO-F2A-PRUEBA-001';
const IMPE_FIX_CONFIRM='REASIGNAR_PRUEBA_14_AL_ASESOR_22';

function impeArgs(array $argv): array {
    $opt=[];
    foreach (array_slice($argv,1) as $arg) {
        if ($arg==='--check'||$arg==='--apply') {
            $key=substr($arg,2);
            if (isset($opt[$key])) throw new RuntimeException('Opción duplicada.');
            $opt[$key]=true;
        } elseif (preg_match('/^--(site|expect-db|backup-root|confirm)=(.+)$/D',$arg,$m)
            && !isset($opt[$m[1]])) {
            $opt[$m[1]]=$m[2];
        } else {
            throw new RuntimeException('Opción desconocida o duplicada.');
        }
    }
    if (empty($opt['site']) || ($opt['check']??false)===($opt['apply']??false)) {
        throw new RuntimeException('Indica --site y exactamente --check o --apply.');
    }
    if (!empty($opt['apply']) &&
        (empty($opt['backup-root']) || empty($opt['expect-db']) ||
            !isset($opt['confirm']) || $opt['confirm']!==IMPE_FIX_CONFIRM)) {
        throw new RuntimeException(
            'Apply requiere --backup-root, --expect-db y la confirmación literal.'
        );
    }
    return $opt;
}

function impeRows(mysqli $db,string $sql,array $values=[]): array {
    $stmt=$db->prepare($sql);
    if ($values) {
        $types='';
        foreach ($values as $v) $types.=is_int($v)?'i':'s';
        $stmt->bind_param($types,...$values);
    }
    try {
        $stmt->execute();
        $res=$stmt->get_result();
        return $res->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}

function impeGuardas(mysqli $db,bool $lock): array {
    $name=(string)($db->query('SELECT DATABASE() nombre')->fetch_assoc()['nombre']??'');
    if ($name!==IMPE_FIX_DB) {
        throw new RuntimeException('Base no autorizada: no se realizó ninguna modificación.');
    }
    $control=impeRows($db,
        'SELECT meta_habilitado,aliados_habilitado FROM prospecto_distribucion_control WHERE id=1');
    if (count($control)!==1 || (int)$control[0]['meta_habilitado']!==0 ||
        (int)$control[0]['aliados_habilitado']!==0) {
        throw new RuntimeException('Distribución Meta/Aliados activa o desconocida.');
    }
    $advisor=impeRows($db,
        "SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id
          WHERE u.id=? AND u.estado=1 AND r.id=3 AND r.estado=1
            AND r.nombre='Asesor de Ventas'",
        [IMPE_FIX_ASESOR_DESPUES]);
    if (count($advisor)!==1) {
        throw new RuntimeException('El asesor 22 no está activo con rol Asesor de Ventas.');
    }
    // Exigir identidad doble: ID, nombre exacto y marcador de procedencia.
    // No se modifica ni se busca por coincidencia parcial de teléfonos.
    $persona=impeRows($db,
        "SELECT id,nombre_completo,telefono_original,telefono_normalizado,correo
           FROM prospectos_comerciales WHERE id=?",
        [IMPE_FIX_PROSPECTO]);
    if (count($persona)!==1 ||
        (string)$persona[0]['nombre_completo']!=='PRUEBA EMBUDO FASE 2A - NO CONTACTAR' ||
        $persona[0]['telefono_original']!==null ||
        $persona[0]['telefono_normalizado']!==null ||
        $persona[0]['correo']!==null) {
        throw new RuntimeException('El prospecto 14 no es la ficha sintética intacta.');
    }
    $origen=impeRows($db,
        "SELECT id FROM prospecto_origenes
           WHERE prospecto_id=?
             AND sistema_origen='IMPE_PRUEBAS'
             AND tipo_registro='FICHA_TEST'
             AND identificador_origen=?",
        [IMPE_FIX_PROSPECTO,IMPE_FIX_ORIGEN]);
    if (count($origen)!==1) {
        throw new RuntimeException('El marcador de la ficha de prueba no coincide.');
    }
    $sql='SELECT id,prospecto_id,asesor_id,asignado_por,asignado_at,fecha_trabajo,
                 proxima_accion_at,finalizado_at,vigente,estado
            FROM prospecto_asignaciones WHERE prospecto_id=?';
    if ($lock) $sql.=' FOR UPDATE';
    $asignaciones=impeRows($db,$sql,[IMPE_FIX_PROSPECTO]);
    if (count($asignaciones)!==1 ||
        (int)$asignaciones[0]['id']!==IMPE_FIX_ASIGNACION ||
        (int)$asignaciones[0]['vigente']!==1 ||
        (string)$asignaciones[0]['estado']!=='NUEVO' ||
        $asignaciones[0]['asignado_por']!==null ||
        $asignaciones[0]['finalizado_at']!==null ||
        $asignaciones[0]['proxima_accion_at']!==null ||
        !in_array((int)$asignaciones[0]['asesor_id'],
                  [IMPE_FIX_ASESOR_ANTES,IMPE_FIX_ASESOR_DESPUES],true)) {
        throw new RuntimeException('La asignación ya cambió: reasignación cancelada.');
    }
    // No alterar un expediente que ya acumuló trabajo real.
    foreach ([
        'prospecto_embudo_etapas' => 'asignacion_id',
        'prospecto_embudo_movimientos' => 'asignacion_id',
        'prospecto_embudo_agenda' => 'asignacion_id',
        'prospecto_embudo_llamadas' => 'asignacion_id'
    ] as $table=>$field) {
        $rows=impeRows($db,"SELECT COUNT(*) total FROM $table WHERE $field=?",
                       [IMPE_FIX_ASIGNACION]);
        if (count($rows)!==1 || (int)$rows[0]['total']!==0) {
            throw new RuntimeException('La ficha ya tiene actividad en '.$table.'; no transferir.');
        }
    }
    // Tampoco reatribuir si hubo distribución formal o seguimiento telefónico.
    $dist=impeRows($db,
        'SELECT COUNT(*) total FROM prospecto_distribucion_registros WHERE prospecto_id=?',
        [IMPE_FIX_PROSPECTO]);
    if (count($dist)!==1 || (int)$dist[0]['total']!==0) {
        throw new RuntimeException('Se encontró distribución previa; bloquear.');
    }
    echo "IDENTIDAD OK: prospecto 14 sintético; asignación 1; sin teléfono.\n";
    echo "OPERACIÓN: asesor ".(int)$asignaciones[0]['asesor_id'].
         " -> asesor 22; etapa NUEVO; sin actividad comercial.\n";
    echo "DISTRIBUCIÓN OK: Meta y Aliados desactivados.\n";
    return $asignaciones[0];
}

function impeManifest(string $root,string $site,string $db,array $before): string {
    $site=realpath($site);
    if (!$site) throw new RuntimeException('Sitio no encontrado.');
    if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) {
        throw new RuntimeException('No se pudo crear respaldo privado.');
    }
    $root=realpath($root);
    if (!$root || $root===$site || strpos($root,$site.DIRECTORY_SEPARATOR)===0) {
        throw new RuntimeException('El respaldo debe estar fuera de public_html.');
    }
    $folder=$root.'/reasignacion-fixture-embudo-'.gmdate('Ymd-His').'-'.getmypid();
    if (!mkdir($folder,0700)) throw new RuntimeException('No se pudo crear carpeta de respaldo.');
    $file=$folder.'/asignacion_antes.json';
    $json=json_encode([
        'operacion'=>'reasignar exclusivamente asignacion 1 / prospecto 14',
        'fecha_utc'=>gmdate('c'),
        'base'=>$db,
        'asignacion_antes'=>$before,
        'asesor_despues'=>IMPE_FIX_ASESOR_DESPUES,
        'rollback_manual'=>'verificar ausencia de actividad antes de cambiar asesor de 22 a 8'
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json) || file_put_contents($file,$json,LOCK_EX)===false) {
        throw new RuntimeException('No se pudo respaldar la asignación.');
    }
    chmod($file,0600);
    echo "RESPALDO PRIVADO: $file\n";
    return $file;
}

try {
    $options=impeArgs($argv);
    $site=realpath((string)$options['site']);
    if (!$site || !is_file($site.'/config/db_connection.php')) {
        throw new RuntimeException('Sitio o conexión no disponibles.');
    }
    require_once $site.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    $name=(string)($db->query('SELECT DATABASE() nombre')->fetch_assoc()['nombre']??'');
    if ($name!==IMPE_FIX_DB) throw new RuntimeException('Nombre de base inesperado.');

    if (!empty($options['check'])) {
        $before=impeGuardas($db,false);
        $done=(int)$before['asesor_id']===IMPE_FIX_ASESOR_DESPUES;
        echo $done
            ?"CHECK OK: YA ASIGNADO AL ASESOR 22. Sin escrituras.\n"
            :"CHECK OK: pendiente de pasar del asesor 8 al 22; sin escrituras.\n";
        exit(0);
    }
    if (!hash_equals(IMPE_FIX_DB,(string)$options['expect-db'])) {
        throw new RuntimeException('--expect-db no coincide con la base autorizada.');
    }

    $before=impeGuardas($db,false);
    if ((int)$before['asesor_id']===IMPE_FIX_ASESOR_DESPUES) {
        echo "SIN CAMBIOS: la asignación 1 ya pertenece al asesor 22.\n";
        exit(0);
    }
    // Respaldo legible antes de modificar y transacción que protege a la ficha.
    impeManifest((string)$options['backup-root'],$site,$name,$before);
    $db->begin_transaction();
    try {
        $locked=impeGuardas($db,true);
        if ((int)$locked['asesor_id']!==IMPE_FIX_ASESOR_ANTES) {
            throw new RuntimeException('La asignación cambió durante la revisión.');
        }
        $stmt=$db->prepare(
            "UPDATE prospecto_asignaciones SET asesor_id=?
              WHERE id=? AND prospecto_id=? AND asesor_id=?
                AND vigente=1 AND estado='NUEVO'"
        );
        $to=IMPE_FIX_ASESOR_DESPUES;
        $a=IMPE_FIX_ASIGNACION;
        $p=IMPE_FIX_PROSPECTO;
        $from=IMPE_FIX_ASESOR_ANTES;
        $stmt->bind_param('iiii',$to,$a,$p,$from);
        $stmt->execute();
        $changed=$stmt->affected_rows;
        $stmt->close();
        if ($changed!==1) throw new RuntimeException('No se actualizó exactamente una asignación.');
        $verified=impeGuardas($db,true);
        if ((int)$verified['asesor_id']!==IMPE_FIX_ASESOR_DESPUES) {
            throw new RuntimeException('La asignación final no coincide.');
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    echo "REASIGNACIÓN OK: prospecto 14 / asignación 1 / asesor 22 / NUEVO.\n";
    echo "Sin cambios en horarios, telefonía, asignación automática ni otras fichas.\n";
} catch (Throwable $e) {
    error_log('[reasignacion_fixture_embudo] '.get_class($e).' codigo='.(int)$e->getCode());
    fwrite(STDERR,'BLOQUEADO: '.$e->getMessage()."\n");
    exit(2);
}
