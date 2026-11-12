<?php
/**
 * Auditoría SOLO LECTURA de distribución Meta/aliados para Gestión Comercial.
 * Nunca imprime personas, teléfono, asesor ni credenciales. No activa motores.
 */
if (PHP_SAPI!=='cli') {http_response_code(403);exit(2);}
$opts=getopt('',['site:','dia:']);
$site=realpath((string)($opts['site']??''));
$dia=(string)($opts['dia']??'');
if (!$site || !is_file($site.'/config/db_connection.php')) {
    fwrite(STDERR,"BLOQUEADO: sitio no reconocido.\n");exit(2);
}
$tz=new DateTimeZone('America/Mexico_City');
$fecha=DateTimeImmutable::createFromFormat('!Y-m-d',$dia,$tz);
if (!$fecha || $fecha->format('Y-m-d')!==$dia ||
    $fecha>(new DateTimeImmutable('today',$tz))) {
    fwrite(STDERR,"BLOQUEADO: especifica una fecha local válida con --dia=AAAA-MM-DD.\n");exit(2);
}
require $site.'/config/db_connection.php';
$db=(new Database())->connect(false);
try {
    $db->query('START TRANSACTION READ ONLY');
    $required=[
        'prospecto_distribucion_control',
        'prospecto_meta_lotes_confirmados',
        'prospecto_distribucion_registros',
        'prospecto_distribucion_pendientes',
        'prospecto_distribucion_corridas',
    ];
    foreach($required as $table){
        $st=$db->prepare(
          'SELECT ENGINE FROM information_schema.TABLES
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->bind_param('s',$table);$st->execute();$r=$st->get_result()->fetch_assoc();
        $st->close();
        if (!$r || strtoupper((string)$r['ENGINE'])!=='INNODB')
            throw new DomainException('Esquema de distribución no disponible.');
    }
    $flag=$db->query("SELECT meta_habilitado,aliados_habilitado
        FROM prospecto_distribucion_control WHERE id=1")->fetch_assoc();
    if (!$flag) throw new DomainException('No está el interruptor central.');
    $st=$db->prepare(
        'SELECT tipo_origen,COUNT(*) eventos,SUM(asignados) asignados,
                SUM(pendientes) pendientes,MAX(fin_at) ultima_finalizacion
         FROM prospecto_distribucion_corridas
         WHERE fecha_jornada=? GROUP BY tipo_origen');
    $st->bind_param('s',$dia);$st->execute();
    $corridas=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $st=$db->prepare(
        'SELECT tipo_origen,COUNT(*) asignados,
                COUNT(DISTINCT asesor_id) asesores,
                MAX(asignado_at) ultimo_registro
         FROM prospecto_distribucion_registros
         WHERE fecha_jornada=? GROUP BY tipo_origen');
    $st->bind_param('s',$dia);$st->execute();
    $reparto=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
    $st=$db->prepare(
        "SELECT COALESCE(MAX(cupo),0) maximo_por_asesor,
                COALESCE(SUM(CASE WHEN cupo>80 THEN 1 ELSE 0 END),0) excedidos
         FROM (
           SELECT asesor_id,COUNT(*) cupo
           FROM prospecto_distribucion_registros
           WHERE tipo_origen='META_ADS' AND fecha_jornada=?
           GROUP BY asesor_id
         ) t");
    $st->bind_param('s',$dia);$st->execute();
    $cupo=$st->get_result()->fetch_assoc();$st->close();
    $st=$db->prepare(
        'SELECT COUNT(*) lotes,COALESCE(SUM(nuevos),0) nuevos
         FROM prospecto_meta_lotes_confirmados
         WHERE confirmado_at>=? AND confirmado_at<?');
    $inicio=$dia.' 00:00:00';
    $fin=$fecha->modify('+1 day')->format('Y-m-d').' 00:00:00';
    $st->bind_param('ss',$inicio,$fin);$st->execute();
    $lotes=$st->get_result()->fetch_assoc();$st->close();
    $rows=$db->query(
       'SELECT tipo_origen,motivo,COUNT(*) total
        FROM prospecto_distribucion_pendientes
        GROUP BY tipo_origen,motivo
        ORDER BY tipo_origen,motivo LIMIT 60')->fetch_all(MYSQLI_ASSOC);
    $db->query('ROLLBACK');
    echo "AUDITORIA_GESTION_COMERCIAL_SOLO_LECTURA\n";
    echo "DIA_LOCAL: ".$dia."\n";
    echo "META_HABILITADO: ".(int)$flag['meta_habilitado']."\n";
    echo "ALIADOS_HABILITADO: ".(int)$flag['aliados_habilitado']."\n";
    echo "LOTES_META_CONFIRMADOS_DIA: ".(int)$lotes['lotes']."\n";
    echo "NUEVOS_META_EN_LOTES_DIA: ".(int)$lotes['nuevos']."\n";
    foreach($corridas as $r) {
        echo "CORRIDAS_".strtoupper($r['tipo_origen']).": ".
            (int)$r['eventos']." | ASIGNADOS: ".(int)$r['asignados'].
            " | PENDIENTES: ".(int)$r['pendientes'].
            " | ULTIMA: ".(string)$r['ultima_finalizacion']."\n";
    }
    foreach($reparto as $r) {
        echo "REGISTROS_".strtoupper($r['tipo_origen']).": ".
            (int)$r['asignados']." | ASESORES: ".(int)$r['asesores']."\n";
    }
    echo "META_CUPO_MAXIMO_POR_ASESOR: ".(int)$cupo['maximo_por_asesor']."\n";
    echo "META_ASESORES_SUPERAN_80: ".(int)$cupo['excedidos']."\n";
    foreach($rows as $r) {
        echo "PENDIENTES_".strtoupper($r['tipo_origen'])."_".
            strtoupper($r['motivo']).": ".(int)$r['total']."\n";
    }
    echo "AVISO: La auditoría de tablas no demuestra que el cron nocturno
se ejecutará mañana. Verificar programación en el panel Hostinger.\n";
    echo "SIN_SQL_DE_ESCRITURA_SIN_CAMBIOS_DE_PROSPECTOS\n";
} catch(Throwable $e) {
    @$db->query('ROLLBACK');
    error_log('[impe_distribucion_readonly] '.get_class($e));
    fwrite(STDERR,"BLOQUEADO: no se pudo completar auditoría de distribución.\n");
    exit(2);
} finally {$db->close();}
