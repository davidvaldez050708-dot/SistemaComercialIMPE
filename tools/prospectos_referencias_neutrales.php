<?php
/** Migración optativa de referencias comerciales no configuradas: nunca se ejecuta desde HTTP. */
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
$args=getopt('',['site:','check','apply']);
try {
    if (isset($args['check'])===isset($args['apply'])) throw new RuntimeException('Selecciona --check o --apply.');
    $site=realpath($args['site']??dirname(__DIR__));
    if (!$site||!is_file($site.'/config/db_connection.php')) throw new RuntimeException('Sitio no válido.');
    require $site.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    $db->begin_transaction();
    try {
        $sql="SELECT id,prospecto_id FROM prospecto_integraciones
            WHERE sistema='ZOHO' AND estado='NO_CONFIGURADO' AND externo_id IS NULL
            AND referencia_estable=CONCAT('impe:prospecto:',prospecto_id) ORDER BY id";
        if (isset($args['apply'])) $sql.=' FOR UPDATE';
        $rows=$db->query($sql)->fetch_all(MYSQLI_ASSOC);
        $legacyBefore=(int)$db->query("SELECT COUNT(*) n FROM prospecto_integraciones WHERE sistema='ZOHO' AND referencia_estable LIKE 'impe:prospecto:%'")->fetch_assoc()['n'];
        $exists=$db->prepare("SELECT id FROM prospecto_integraciones WHERE sistema='ZOHO' AND referencia_estable=? LIMIT 1");
        $update=$db->prepare("UPDATE prospecto_integraciones SET referencia_estable=? WHERE id=? AND sistema='ZOHO'
            AND estado='NO_CONFIGURADO' AND externo_id IS NULL AND referencia_estable=CONCAT('impe:prospecto:',prospecto_id)");
        $aplicadas=0;$conflictos=0;
        foreach ($rows as $row) {
            $target='CRM-PROS-'.str_pad((string)(int)$row['prospecto_id'],5,'0',STR_PAD_LEFT);
            $exists->bind_param('s',$target);$exists->execute();
            if ($exists->get_result()->fetch_assoc()) {$conflictos++;continue;}
            if (isset($args['apply'])) {
                $key=(int)$row['id'];$update->bind_param('si',$target,$key);$update->execute();
                if ($update->affected_rows!==1) throw new RuntimeException('Registro modificado durante la operación.');
                $aplicadas++;
            }
        }
        $exists->close();$update->close();
        if ($conflictos!==0) throw new RuntimeException('Referencias existentes en conflicto: no se aplicó ningún cambio.');
        $legacyAfter=(int)$db->query("SELECT COUNT(*) n FROM prospecto_integraciones WHERE sistema='ZOHO' AND referencia_estable LIKE 'impe:prospecto:%'")->fetch_assoc()['n'];
        if (isset($args['check'])) $db->rollback();else $db->commit();
        echo json_encode([
            'estado'=>isset($args['check'])?'PREVISUALIZACION_SIN_ESCRITURAS':'REFERENCIAS_ACTUALIZADAS',
            'candidatas'=>count($rows),'actualizadas'=>$aplicadas,'conflictos'=>$conflictos,
            'referencias_historicas_antes'=>$legacyBefore,'referencias_historicas_despues'=>$legacyAfter,
            'solo_zoho_no_configurado'=>true,'otras_tablas_modificadas'=>false
        ],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $e) {$db->rollback();throw $e;}
} catch (Throwable $e) {
    fwrite(STDERR,json_encode(['estado'=>'ERROR_OPERACION_NO_COMPLETADA','tipo'=>get_class($e),'codigo'=>(int)$e->getCode()],JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
}
