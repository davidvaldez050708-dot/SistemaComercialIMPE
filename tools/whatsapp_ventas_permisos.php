<?php
/** CLI: ajusta sólo consulta global de supervisión y alcance de Ventas. */
if(PHP_SAPI!=='cli' || count($argv)!==4)exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
try {
    [$script,$site,$mode,$snapshot]=$argv;
    $public=realpath($site);$directory=realpath(dirname($snapshot));
    if(!$public || !$directory || $directory===$public || strpos($directory,$public.DIRECTORY_SEPARATOR)===0)throw new RuntimeException('El respaldo debe estar fuera de public_html.');
    require $public.'/config/db_connection.php';$db=(new Database())->connect(false);
    foreach(['roles','permisos','rol_permisos'] as $table){$r=$db->query("SHOW TABLE STATUS LIKE '$table'")->fetch_assoc();if(strtoupper($r['Engine']??'')!=='INNODB')throw new RuntimeException('Se requieren permisos transaccionales.');}
    $ids=array_column($db->query("SELECT codigo,id FROM permisos WHERE estado=1 AND codigo IN ('whatsapp.ver','whatsapp.gestionar_conversaciones','whatsapp.responder_todas')")->fetch_all(MYSQLI_ASSOC),'id','codigo');
    if(count($ids)!==3 || $db->query('SELECT id FROM roles WHERE id IN (1,2,3,6)')->num_rows!==4)throw new RuntimeException('Falta el catálogo de roles o permisos.');
    $view=(int)$ids['whatsapp.ver'];$global=(int)$ids['whatsapp.gestionar_conversaciones'];$reply=(int)$ids['whatsapp.responder_todas'];
    $where="(rol_id IN(1,2,6) AND permiso_id IN($view,$global)) OR (rol_id=3 AND permiso_id IN($global,$reply))";
    if($mode==='check'){echo 'PERMISOS_PRECHECK_OK';exit;}
    if(!in_array($mode,['apply','restore'],true))throw new RuntimeException('Modo inválido.');
    $db->begin_transaction();
    try {
        $before=$db->query("SELECT rol_id,permiso_id FROM rol_permisos WHERE $where ORDER BY rol_id,permiso_id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        if($mode==='apply'){
            if(file_exists($snapshot)){echo 'PERMISOS_YA_APLICADOS';$db->rollback();exit;}
            $db->query("INSERT IGNORE INTO rol_permisos(rol_id,permiso_id) SELECT r.id,p.id FROM roles r CROSS JOIN permisos p WHERE r.id IN(1,2,6) AND p.id IN($view,$global)");
            $db->query("DELETE FROM rol_permisos WHERE rol_id=3 AND permiso_id IN($global,$reply)");
            $after=$db->query("SELECT rol_id,permiso_id FROM rol_permisos WHERE $where ORDER BY rol_id,permiso_id")->fetch_all(MYSQLI_ASSOC);
            $f=fopen($snapshot,'x');if(!$f)throw new RuntimeException('No se pudo guardar el respaldo.');chmod($snapshot,0600);
            try{$data=json_encode(['site'=>$public,'before'=>$before,'after'=>$after],JSON_THROW_ON_ERROR);if(fwrite($f,$data)!==strlen($data))throw new RuntimeException('Respaldo incompleto.');fflush($f);}finally{fclose($f);}
            $db->commit();echo 'PERMISOS_VENTAS_OK';
        }else{
            $record=json_decode(file_get_contents($snapshot),true,512,JSON_THROW_ON_ERROR);
            if(($record['site']??null)!==$public || ($record['after']??null)!==$before)throw new RuntimeException('Los permisos cambiaron después de la publicación; revisar antes de restaurar.');
            $db->query("DELETE FROM rol_permisos WHERE $where");$s=$db->prepare('INSERT IGNORE INTO rol_permisos(rol_id,permiso_id) VALUES(?,?)');
            foreach($record['before'] as $row){$role=(int)$row['rol_id'];$permission=(int)$row['permiso_id'];$s->bind_param('ii',$role,$permission);$s->execute();}$s->close();
            $db->commit();echo 'PERMISOS_RESTAURADOS';
        }
    }catch(Throwable $e){$db->rollback();if($mode==='apply' && isset($f) && is_file($snapshot))unlink($snapshot);throw $e;}
}catch(Throwable $e){fwrite(STDERR,'No se completó el ajuste de permisos. Revisar respaldo privado.');exit(2);}
