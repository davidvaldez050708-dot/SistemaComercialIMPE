<?php
/**
 * Informe PREVIO de conciliación comercial para V3: SOLO LECTURA.
 *
 * No publica SQL ni modifica resultados, etapas, tarjetas o PBX.
 * No imprime IDs, nombres, teléfonos, programas concretos, observaciones
 * ni referencias de llamadas. Muestra evidencia codificada por caso.
 *
 * Uso (fuera de public_html):
 * IMPE_SITE_ROOT=/ruta/public_html php este_archivo.php --check
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("BLOQUEADO: disponible sólo por consola.\n");
}
if ($argc !== 2 || $argv[1] !== '--check') {
    fwrite(STDERR, "BLOQUEADO: use solamente --check.\n");
    exit(2);
}
$root = getenv('IMPE_SITE_ROOT');
if (!is_string($root) || trim($root)==='') {
    fwrite(STDERR, "BLOQUEADO: falta IMPE_SITE_ROOT.\n");
    exit(2);
}
$root = realpath($root);
if (!$root || !is_dir($root) || !is_file($root.'/config/db_connection.php')) {
    fwrite(STDERR, "BLOQUEADO: raíz del sitio no disponible.\n");
    exit(2);
}
$src = realpath(__FILE__);
if ($src && strpos($src, $root.DIRECTORY_SEPARATOR)===0) {
    fwrite(STDERR, "BLOQUEADO: el diagnóstico debe permanecer fuera del sitio público.\n");
    exit(2);
}
require_once $root.'/config/db_connection.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);

$db = null;
try {
    $db = (new Database())->connect(false);
    $db->query('SET SESSION TRANSACTION READ ONLY');
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);

    $necesarias = [
        'prospecto_asignaciones',
        'prospecto_embudo_etapas',
        'prospecto_embudo_llamadas',
        'prospecto_embudo_agenda',
        'prospecto_intereses',
        'telefonia_ventas_resultados',
        'telefonia_ventas_resultado_selecciones'
    ];
    $meta = $db->prepare("SELECT ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    foreach ($necesarias as $t) {
        $meta->bind_param('s', $t);
        $meta->execute();
        $engine = $meta->get_result()->fetch_assoc()['ENGINE']??'';
        if (strtoupper((string)$engine)!=='INNODB') {
            throw new RuntimeException('Esquema requerido no compatible.');
        }
    }
    $meta->close();

    echo "=== CONCILIACION PREVIA V3 · SOLO LECTURA ===\n";
    $conteos=$db->query("SELECT e.etapa,COUNT(*) total
         FROM prospecto_embudo_etapas e
         JOIN prospecto_asignaciones a ON a.id=e.asignacion_id
         WHERE a.vigente=1
         GROUP BY e.etapa ORDER BY e.etapa")->fetch_all(MYSQLI_ASSOC);
    echo "=== ETAPAS DE ASIGNACIONES VIGENTES (CONTEOS) ===\n";
    foreach($conteos as $x){
        $etapa=(string)$x['etapa'];
        if (!preg_match('/^[A-Z0-9_]{1,40}$/D',$etapa)) {
            throw new RuntimeException('Código de etapa no reconocido.');
        }
        echo $etapa.': '.(int)$x['total']."\n";
    }

    // Todos los criterios se consultan de filas persistidas y vinculadas
    // a la asignación. Un resultado seleccionado ≠ calificación verificada.
    // La información del prospecto queda DENTRO de la BD; ningún ID sale.
    $sql="SELECT e.etapa,
            (SELECT COUNT(*) FROM prospecto_embudo_llamadas l
                WHERE l.asignacion_id=a.id) total_pbxs,
            EXISTS(SELECT 1 FROM prospecto_intereses i
                WHERE i.prospecto_id=a.prospecto_id
                AND i.programa_codigo IS NOT NULL
                AND i.programa_codigo<>'') tiene_programa,
            EXISTS(SELECT 1 FROM prospecto_embudo_agenda ag
                WHERE ag.asignacion_id=a.id
                AND (ag.proxima_accion_at IS NOT NULL OR
                     ag.revision_fecha IS NOT NULL)) tiene_tarea,
            EXISTS(SELECT 1 FROM prospecto_embudo_llamadas l
                JOIN telefonia_ventas_resultados r ON r.usuario_id=l.usuario_id
                AND BINARY r.extension=BINARY l.extension
                AND BINARY r.pbx_call_id=BINARY l.pbx_call_id
                WHERE l.asignacion_id=a.id AND
                    (r.resultado='PROSPECTO_POTENCIAL' OR
                    EXISTS (SELECT 1 FROM telefonia_ventas_resultado_selecciones s
                        WHERE s.resultado_id=r.id AND
                            s.codigo='PROSPECTO_POTENCIAL')))
                selecciono_potencial,
            EXISTS(SELECT 1 FROM prospecto_embudo_llamadas l
                JOIN telefonia_ventas_resultados r ON r.usuario_id=l.usuario_id
                AND BINARY r.extension=BINARY l.extension
                AND BINARY r.pbx_call_id=BINARY l.pbx_call_id
                WHERE l.asignacion_id=a.id
                  AND r.resultado='INSCRITO') declaro_inscrito
        FROM prospecto_asignaciones a
        JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
        WHERE a.vigente=1 AND e.etapa IN (
            'INTERESADO','PAGO_CONFIRMADO','SEGUIMIENTO'
        )
        ORDER BY e.etapa,a.id LIMIT 101";
    $rows=$db->query($sql)->fetch_all(MYSQLI_ASSOC);
    if(count($rows)>100) {
        throw new RuntimeException('Más de cien casos; requiere auditoría por lotes.');
    }
    echo "=== EVIDENCIA POR CASO (SIN DATOS PERSONALES) ===\n";
    if (!$rows) echo "SIN CASOS LEGACY O SEGUIMIENTOS VIGENTES.\n";
    $caso=0;
    foreach ($rows as $r){
        $caso++;
        $code=(string)$r['etapa'];
        $n=(int)$r['total_pbxs'];
        echo 'CASO '.$caso.' · ETAPA '.$code.
             ' · LLAMADAS VINCULADAS '.$n."\n";
        echo '  PROGRAMA CODIFICADO: '.((int)$r['tiene_programa']===1?'SI':'NO')."\n";
        echo '  POTENCIAL DECLARADO EN LLAMADA: '.((int)$r['selecciono_potencial']===1?'SI':'NO')."\n";
        echo '  PROXIMA ACCION REGISTRADA: '.((int)$r['tiene_tarea']===1?'SI':'NO')."\n";
        echo '  INSCRITO DECLARADO EN LLAMADA: '.((int)$r['declaro_inscrito']===1?'SI':'NO')."\n";
        echo '  REQUISITOS/DOCUMENTOS CONFIRMADOS: NO DETERMINABLE POR ESTE DIAGNOSTICO'."\n";
    }
    $db->commit();
    echo "=== RESULTADO ===\n";
    echo "CHECK OK: consulta sin cambios y sin datos personales.\n";
    echo "ATENCION: declarar Potencial o Inscrito por telefono NO demuestra requisitos, pago ni inscripcion institucional.\n";
} catch (Throwable $e){
    if ($db instanceof mysqli) {
        try {$db->rollback();} catch (Throwable $ignored) {}
    }
    fwrite(STDERR,"BLOQUEADO: no se pudo comprobar la conciliación. Sitio intacto.\n");
    exit(3);
} finally {
    if ($db instanceof mysqli) {
        try {$db->close();} catch (Throwable $ignored) {}
    }
}
