<?php
/** Crea fichas ficticias con un teléfono autorizado compartido para probar el embudo del asesor 22. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}
$site = realpath($argv[1] ?? '');
$mode = $argv[2] ?? '';
$phone = $argv[3] ?? '';
$normalized = '+52'.$phone;
if (!$site || !preg_match('/^[0-9]{10}$/D', $phone) || !in_array($mode, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Uso: php archivo.php SITIO --check|--apply TELEFONO\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $site.'/config/db_connection.php';
require_once $site.'/app/services/ProspectoCentralRepository.php';
$db = (new Database())->connect(false);
$repo = new ProspectoCentralRepository($db);
$cases = [
    'EVIDENCIA' => 'Bloqueo sin conversación',
    'AGENDA' => 'Agenda y reprogramación',
    'RETOMAR' => 'Retomar contacto',
    'RECUPERACION' => 'Recuperación sin evidencia',
    'DESCARTE' => 'Descarte sin evidencia',
    'INSCRIPCION' => 'Validación de inscripción',
];
$apply = $mode === '--apply';
try {
    $db->begin_transaction($apply ? 0 : MYSQLI_TRANS_START_READ_ONLY);
    $lock = $apply ? ' FOR UPDATE' : '';
    $advisor = $repo->consulta("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id
        WHERE u.id=22 AND u.estado=1 AND r.estado=1 AND r.nombre='Asesor de Ventas'".$lock);
    if ($advisor->num_rows !== 1) {
        throw new DomainException('El asesor 22 debe estar activo y tener rol Asesor de Ventas.');
    }
    $today = new DateTimeImmutable('today', new DateTimeZone('America/Mexico_City'));
    $output = [];
    foreach ($cases as $key => $label) {
        $marker = 'EMBUDO22-ESCENARIOS-V1-'.$key;
        $name = 'PRUEBA 22 · '.$label;
        $origin = $repo->consulta("SELECT o.prospecto_id,p.nombre_completo,p.telefono_original,p.telefono_normalizado
            FROM prospecto_origenes o JOIN prospectos_comerciales p ON p.id=o.prospecto_id
            WHERE o.sistema_origen='IMPE_PRUEBAS' AND o.tipo_registro='ESCENARIO_EMBUDO'
              AND o.identificador_origen=?".$lock, [$marker])->fetch_assoc();
        if ($origin) {
            $assigned = $repo->consulta('SELECT asesor_id FROM prospecto_asignaciones
                WHERE prospecto_id=? AND vigente=1'.$lock, [(int)$origin['prospecto_id']])->fetch_assoc();
            if ($origin['nombre_completo'] !== $name || (int)($assigned['asesor_id'] ?? 0) !== 22) {
                throw new DomainException('Una ficha de prueba existente requiere revisión: '.$key);
            }
            $id = (int)$origin['prospecto_id'];
            $origins = $repo->consulta('SELECT id FROM prospecto_origenes WHERE prospecto_id=?'.$lock, [$id]);
            if ($origins->num_rows !== 1) {
                throw new DomainException('La ficha tiene otra procedencia: '.$key);
            }
            $previous = (string)$origin['telefono_normalizado'];
            if (($previous !== '' && $previous !== $normalized) ||
                ($previous === '' && trim((string)$origin['telefono_original']) !== '')) {
                throw new DomainException('La ficha ya tiene otro teléfono: '.$key);
            }
            if ($apply && $previous === '') {
                $repo->ejecutar("UPDATE prospectos_comerciales
                    SET telefono_original=?,telefono_normalizado=?,integridad_estado='BASICOS_COMPLETOS'
                    WHERE id=?", [$phone,$normalized,$id]);
            }
            $output[] = $key.' · existente';
            continue;
        }
        if (!$apply) {
            $output[] = $key.' · por crear';
            continue;
        }
        $id = $repo->crearPersona(['nombre_completo'=>$name, 'pais_nombre'=>'México', 'telefono_original'=>$phone]);
        $repo->guardarOrigen($id, [
            'tipo_origen'=>'FORMULARIO_GENERAL', 'sistema_origen'=>'IMPE_PRUEBAS',
            'tipo_registro'=>'ESCENARIO_EMBUDO', 'identificador_origen'=>$marker,
            'datos_originales_ref'=>'prueba:'.$marker, 'formulario_nombre'=>'Pruebas de Gestión comercial',
        ]);
        $day = ($key === 'RETOMAR' ? $today->modify('-1 day') : $today)->format('Y-m-d');
        $repo->ejecutar("INSERT INTO prospecto_asignaciones
            (prospecto_id,asesor_id,asignado_at,fecha_trabajo,vigente,estado)
            VALUES (?,22,UTC_TIMESTAMP(),?,1,'NUEVO')", [$id,$day]);
        $output[] = $key.' · creada #'.$id;
    }
    $db->commit();
    echo implode(PHP_EOL, $output).PHP_EOL.($apply ? 'APPLY_OK' : 'CHECK_OK').PHP_EOL;
} catch (Throwable $error) {
    $db->rollback();
    fwrite(STDERR, 'BLOQUEADO: '.($error instanceof DomainException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
} finally {
    $db->close();
}
