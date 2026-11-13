<?php
/** Prueba controlada del CSV de Campañas de 82 filas. Ejecutar fuera de public_html. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
[$script, $site, $mode, $day] = array_pad($argv, 4, '');
if (!in_array($mode, ['check', 'apply', 'cleanup'], true) ||
    !preg_match('/^20\d\d-\d\d-\d\d$/D', $day) ||
    !checkdate((int)substr($day, 5, 2), (int)substr($day, 8, 2), (int)substr($day, 0, 4))) {
    fwrite(STDERR,"Uso: php script.php SITIO check|apply|cleanup AAAA-MM-DD\n"); exit(2);
}
umask(0077);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $site.'/config/db_connection.php';
require_once $site.'/app/services/ProspectoCentralRepository.php';
$db = (new Database())->connect(false);
$repo = new ProspectoCentralRepository($db);
$write = static fn($sql, $args) => $repo->ejecutar($sql, $args);
$q = static fn($sql, $args = []) => $repo->consulta($sql, $args);
$fail = static function ($message) { throw new DomainException($message); };
$ids = $phones = [];
for ($i = 1; $i <= 82; $i++) {
    $ids[] = (string)(910000000000000 + $i);
    $phones[] = sprintf('+120255501%02d', $i);
}
$list = implode(',', array_fill(0, 82, '?'));
try {
    if ($mode !== 'check' && !(int)$q("SELECT GET_LOCK('impe_prospectos_distribucion_v1',5) ok")->fetch_assoc()['ok']) {
        $fail('Distribución ocupada.');
    }
    $db->begin_transaction($mode === 'check' ? MYSQLI_TRANS_START_READ_ONLY : 0);
    $lock = $mode === 'check' ? '' : ' FOR UPDATE';
    $control = $q('SELECT * FROM prospecto_distribucion_control WHERE id=1'.$lock)->fetch_assoc();
    if (!$control || (int)$control['meta_habilitado'] || (int)$control['aliados_habilitado']) {
        $fail('Los dos motores deben estar apagados.');
    }
    $rows = $q("SELECT o.* FROM prospecto_origenes o
        WHERE o.meta_lead_id IN ($list) OR
        (o.tipo_origen='META_ADS' AND o.tipo_registro='LEAD' AND o.identificador_origen IN ($list))
        ORDER BY o.meta_lead_id".$lock, array_merge($ids, $ids))->fetch_all(MYSQLI_ASSOC);
    $personas = $q("SELECT * FROM prospectos_comerciales WHERE telefono_normalizado IN ($list)".$lock,
        $phones)->fetch_all(MYSQLI_ASSOC);
    $weekday = (int)(new DateTimeImmutable($day))->format('N');
    $programs = $q("SELECT DISTINCT ap.programa_codigo FROM prospecto_asesores_config c
        JOIN usuarios u ON u.id=c.usuario_id AND u.estado=1
        JOIN roles r ON r.id=u.rol_id AND r.estado=1 AND r.nombre='Asesor de Ventas'
        JOIN prospecto_asesores_programas ap ON ap.usuario_id=u.id
        JOIN formulario_programas f ON f.codigo=ap.programa_codigo AND f.activo=1
        JOIN prospecto_asesores_horarios h ON h.usuario_id=u.id AND h.dia_semana=?
        WHERE c.usuario_id=22 AND c.activo=1 AND h.hora_desde<h.hora_hasta", [$weekday]
    )->fetch_all(MYSQLI_ASSOC);
    $programs = array_column($programs, 'programa_codigo');
    if ($mode === 'check') {
        if ($rows || $personas) $fail('Ya existe un ID o teléfono del CSV.');
        if (!$programs) $fail('El asesor 22 no tiene programa y turno activo para la jornada.');
        echo 'CHECK_OK: 82 IDs y teléfonos libres. Programas habilitados: '.implode(', ', $programs)."\n";
    } else {
        if (count($rows) !== 82 || count($personas) !== 82) $fail('Se requiere el lote ficticio completo de 82 filas.');
        $byId = array_column($personas, null, 'id');
        $lote = (string)$rows[0]['lote_importacion_ref'];
        if (!preg_match('/^[0-9a-f]{32}$/D', $lote)) $fail('Lote inválido.');
        $confirmed = $q('SELECT * FROM prospecto_meta_lotes_confirmados WHERE lote_ref=? FOR UPDATE', [$lote])->fetch_assoc();
        $history = $q('SELECT * FROM prospecto_meta_importaciones_historial WHERE lote_ref=? FOR UPDATE', [$lote])->fetch_assoc();
        if ((int)($confirmed['nuevos']??0) !== 82 || (int)($history['nuevos']??0) !== 82 ||
            (int)$q('SELECT COUNT(*) n FROM prospecto_origenes WHERE lote_importacion_ref=?', [$lote])->fetch_assoc()['n'] !== 82) {
            $fail('La confirmación y el historial deben corresponder sólo al CSV de prueba.');
        }
        $backup = [];
        foreach ($rows as $i => $row) {
            $pid = (int)$row['prospecto_id'];
            $oid = (int)$row['id'];
            $person = $byId[$pid] ?? [];
            if ($row['meta_lead_id'] !== $ids[$i] || $row['lote_importacion_ref'] !== $lote ||
                $row['tipo_origen'] !== 'META_ADS' || $row['origen_padre_id'] !== null ||
                $row['campania_id'] !== 'c:910001' || $row['formulario_id'] !== 'f:910002' ||
                ($person['telefono_normalizado'] ?? '') !== $phones[$i] ||
                ($person['nombre_completo'] ?? '') !== sprintf('Prueba Sintética %03d', $i+1) ||
                (int)$q('SELECT COUNT(*) n FROM prospecto_origenes WHERE prospecto_id=?', [$pid])->fetch_assoc()['n'] !== 1) {
                $fail('Una fila no coincide con el CSV o tiene otra procedencia.');
            }
            $interests = $q('SELECT * FROM prospecto_intereses WHERE origen_id=? FOR UPDATE', [$oid])->fetch_all(MYSQLI_ASSOC);
            if ($mode === 'apply' && (count($interests) !== 1 || !in_array($interests[0]['programa_codigo'], $programs, true))) {
                $fail('El programa del lote no coincide con un programa y turno del asesor 22.');
            }
            $assign = $q('SELECT * FROM prospecto_asignaciones WHERE prospecto_id=? FOR UPDATE', [$pid])->fetch_all(MYSQLI_ASSOC);
            $dist = $q('SELECT * FROM prospecto_distribucion_registros WHERE prospecto_id=? FOR UPDATE', [$pid])->fetch_assoc();
            $pending = $q('SELECT * FROM prospecto_distribucion_pendientes WHERE prospecto_id=? FOR UPDATE', [$pid])->fetch_assoc();
            if (($assign || $dist) && (count($assign)!==1 || !$dist || $i>=80 ||
                (int)$assign[0]['asesor_id']!==22 || (int)$assign[0]['vigente']!==1 ||
                $assign[0]['estado']!=='NUEVO' || $assign[0]['proxima_accion_at']!==null ||
                (int)$dist['asignacion_id']!==(int)$assign[0]['id'] || (int)$dist['origen_id']!==$oid ||
                (int)$dist['asesor_id']!==22 || $dist['tipo_origen']!=='META' ||
                $dist['lote_ref']!==$lote || $dist['fecha_jornada']!==$day)) $fail('Se detectó una asignación ajena o gestionada.');
            if ($pending && ($i<80 || (int)$pending['origen_id']!==$oid ||
                $pending['tipo_origen']!=='META' || $pending['motivo']!=='SIN_ASESOR_O_CUPO')) $fail('Se detectó un pendiente ajeno.');
            $backup[] = compact('person','row','interests','assign','dist','pending');
        }
        if ($mode === 'apply') {
            if ((int)$q("SELECT COUNT(*) n FROM prospecto_distribucion_registros
                WHERE asesor_id=22 AND tipo_origen='META' AND fecha_jornada=? AND NOT (lote_ref <=> ?)",
                [$day,$lote])->fetch_assoc()['n']) $fail('El asesor ya tiene cupo consumido en esa jornada.');
            foreach ($backup as $i => $entry) {
                $pid = (int)$entry['row']['prospecto_id']; $oid = (int)$entry['row']['id'];
                if ($i<80 && !$entry['assign']) {
                    $write("INSERT INTO prospecto_asignaciones
                        (prospecto_id,asesor_id,asignado_at,fecha_trabajo,vigente,estado)
                        VALUES (?,22,UTC_TIMESTAMP(),?,1,'NUEVO')", [$pid,$day]);
                    $aid = (int)$db->insert_id;
                    $write("INSERT INTO prospecto_distribucion_registros
                        (prospecto_id,asignacion_id,asesor_id,origen_id,tipo_origen,fecha_jornada,lote_ref,asignado_at)
                        VALUES (?,?,22,?,'META',?,?,UTC_TIMESTAMP())", [$pid,$aid,$oid,$day,$lote]);
                }
                if ($i>=80 && !$entry['pending']) {
                    $write("INSERT INTO prospecto_distribucion_pendientes
                        (prospecto_id,origen_id,tipo_origen,motivo,revisado_at)
                        VALUES (?,?,'META','SIN_ASESOR_O_CUPO',UTC_TIMESTAMP())", [$pid,$oid]);
                }
            }
        } else {
            $allowed = ['prospectos_comerciales','prospecto_origenes','prospecto_intereses',
                'prospecto_asignaciones','prospecto_distribucion_registros','prospecto_distribucion_pendientes'];
            $columns = $q("SELECT TABLE_NAME t,COLUMN_NAME c FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('prospecto_id','asignacion_id','origen_id')
                AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE')")->fetch_all(MYSQLI_ASSOC);
            foreach ($columns as $col) {
                if (in_array($col['t'],$allowed,true)) continue;
                $key = ['prospecto_id'=>'prospecto_id','origen_id'=>'id'][$col['c']]??null;
                $values = $key ? array_column($rows,$key) :
                    array_values(array_filter(array_map(static fn($e)=>$e['assign'][0]['id']??null,$backup)));
                if (!$values) continue;
                $params = implode(',',array_fill(0,count($values),'?'));
                $table = str_replace('`','``',$col['t']);
                if ($q("SELECT 1 FROM `$table` WHERE ".$col['c']." IN ($params) LIMIT 1",$values)->num_rows) {
                    $fail('La prueba tiene actividad vinculada en '.$col['t'].'.');
                }
            }
            $file = __DIR__.'/respaldo-campanas-'.bin2hex(random_bytes(8)).'.json';
            $data = json_encode(compact('confirmed','history','backup'), JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
            if (file_put_contents($file,$data,LOCK_EX)!==strlen($data)) $fail('No se pudo respaldar el lote.');
            chmod($file,0600);
            foreach ($backup as $entry) {
                $pid = (int)$entry['row']['prospecto_id']; $oid = (int)$entry['row']['id'];
                if ($entry['pending']) $write('DELETE FROM prospecto_distribucion_pendientes WHERE prospecto_id=?',[$pid]);
                if ($entry['dist']) $write('DELETE FROM prospecto_distribucion_registros WHERE prospecto_id=?',[$pid]);
                if ($entry['assign']) $write('DELETE FROM prospecto_asignaciones WHERE id=?',[(int)$entry['assign'][0]['id']]);
                $write('DELETE FROM prospecto_intereses WHERE origen_id=?',[$oid]);
                $write('DELETE FROM prospecto_origenes WHERE id=?',[$oid]);
                $write('DELETE FROM prospectos_comerciales WHERE id=?',[$pid]);
            }
            $write('DELETE FROM prospecto_meta_importaciones_historial WHERE lote_ref=?',[$lote]);
            $write('DELETE FROM prospecto_meta_lotes_confirmados WHERE lote_ref=?',[$lote]);
        }
    }
    $db->commit();
    if ($mode === 'apply') echo "APPLY_OK: 80 en Nuevos para asesor 22 y 2 pendientes.\n";
    if ($mode === 'cleanup') echo "LIMPIEZA_OK: 82 fichas y lote eliminados. Respaldo: $file\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'BLOQUEADO: '.($e instanceof DomainException?$e->getMessage():get_class($e)).PHP_EOL);
    exit(1);
} finally {
    $db->close();
}
